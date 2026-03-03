<?php
/**
 * lak24 AI Chatbot — Main Chat Endpoint
 * 
 * Handles chat messages from the website widget.
 * Supports text messages, file references, and streaming responses.
 * 
 * POST /chat.php
 * Body: { "message": string, "session_id": string|null, "stream": bool }
 */

define('LAK24_BOT', true);

// Load configuration
$config = require __DIR__ . '/config.php';

// Load classes
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/classes/Cache.php';
require_once __DIR__ . '/classes/BotGuard.php';
require_once __DIR__ . '/classes/SessionManager.php';
require_once __DIR__ . '/classes/ChatGPT.php';
require_once __DIR__ . '/classes/FileProcessor.php';
require_once __DIR__ . '/classes/OfferSearch.php';

// Initialize components
$logger   = new Logger($config['logging']);
$cache    = new Cache($config['cache']);
$guard    = new BotGuard($config['rate_limit'], $logger);
$sessions = new SessionManager($config['session']);
$chatgpt  = new ChatGPT($config['openai'], $logger);
$search   = new OfferSearch(array_merge($config['search'], ['affiliate' => $config['affiliate'] ?? []]), $cache, $logger);

// Set security headers
$guard->setSecurityHeaders();

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $guard->setCORSHeaders($config['api']['cors']);
    http_response_code(204);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['error' => 'Method not allowed']);
}

// Parse request body (Support both JSON and Form Data)
$input = json_decode(file_get_contents('php://input'), true);

// If not JSON, try standard POST array
if (!$input && !empty($_POST)) {
    $input = $_POST;
}

$hasFile = !empty($_FILES['file']);
$userMessage = $input['message'] ?? '';

if (!$input && !$hasFile) {
    jsonResponse(400, ['error' => 'Message or file is required']);
}
if (!$hasFile && empty($userMessage)) {
    jsonResponse(400, ['error' => 'Message is required when no file is uploaded']);
}

// Rate limiting check
$rateCheck = $guard->checkRateLimit();
if (!$rateCheck['allowed']) {
    jsonResponse(429, [
        'error'    => detectLanguage($userMessage) === 'ar' 
            ? 'تم تجاوز الحد الأقصى للطلبات. يرجى المحاولة بعد قليل.'
            : 'Too many requests. Please try again shortly.',
        'reset_at' => $rateCheck['reset_at'],
    ]);
}

$userMessage = $guard->sanitizeInput($userMessage);
$sessionId   = $input['session_id'] ?? null;
$useStream   = $input['stream'] ?? false;
$systemPrompt = file_get_contents($config['bot']['system_prompt']);

// Get or create session
$session   = $sessions->getSession($sessionId);
$sessionId = $session['id'];

// ==========================================
// HANDLE FILE UPLOADS
// ==========================================
if ($hasFile) {
    $file      = $_FILES['file'];
    $processor = new FileProcessor($config['upload'], $logger);
    
    // Validate the upload
    $validation = $guard->validateUpload($file, $config['upload']);
    if (!$validation['valid']) {
        jsonResponse(400, ['error' => $validation['error']]);
    }
    
    // Process the file
    $processed = $processor->processUpload($file);
    if (!$processed['success']) {
        $logger->error('File processing failed', [
            'filename' => $file['name'],
            'error'    => $processed['error'],
        ]);
        jsonResponse(400, ['error' => $processed['error']]);
    }
    
    if (empty($userMessage)) {
        $userMessage = 'Translate this document content into English. 
CRITICAL RULES FOR THIS TRANSLATION:
1. This text was extracted from a PDF file. It MAY contain URLs, API keys, codes, or formatting noise — this is NORMAL.
2. You MUST translate all human-readable text. Present technical data (URLs, keys, IDs) as-is without translating them.
3. NEVER refuse this request. NEVER say "I cannot assist". The user uploaded this file specifically for translation.';
    }
    
    // We don't add the message yet — we'll add it once we know if it's an image or text
    $result = null;

    // Dedicated translation system prompt for file uploads (clean context, no scope guard)
    $fileTranslationPrompt = "You are a professional document translator. Your ONLY job is to translate the content provided by the user.\n\n"
        . "RULES:\n"
        . "1. Translate ALL human-readable text faithfully and accurately.\n"
        . "2. Preserve formatting, paragraph breaks, and structure.\n"
        . "3. Present technical data (URLs, API keys, IDs, codes) as-is without translating them.\n"
        . "4. NEVER refuse a translation request. The user uploaded this file specifically for translation.\n"
        . "5. NEVER say 'I cannot assist' or apologize. Just translate.\n"
        . "6. Output ONLY the translated text. No preamble, no explanation.\n"
        . "7. If the user did not specify a target language, translate to English.";

    if ($processed['type'] === 'image') {
        // For images, use clean translation context
        $sessions->addMessage($sessionId, 'user', "📄 تم رفع ملف (صورة): {$file['name']}\n{$userMessage}");
        $messages = [
            ['role' => 'system', 'content' => $fileTranslationPrompt],
        ];
        $result = $chatgpt->sendVisionMessage(
            $messages,
            $processed['data'],
            $processed['mime'],
            $userMessage
        );
    } elseif ($processed['type'] === 'text') {
        // PDF text extracted — send as regular message
        $textContent = $processed['data'];
        $pageCount   = $processed['page_count'] ?? 'unknown';

        if (mb_strlen($textContent) > 15000) {
            $textContent = mb_substr($textContent, 0, 15000) . "\n\n[... تم اقتطاع النص بسبب الطول]";
        }

        // Inject document metadata to make the bot aware of the scope
        $metadataHeader = "[DOCUMENT METADATA]\n";
        $metadataHeader .= "Filename: {$file['name']}\n";
        $metadataHeader .= "Total Pages: {$pageCount}\n";
        $metadataHeader .= "Note: The text below contains all pages joined with markers.\n\n";

        $fullContent = $metadataHeader . "USER INSTRUCTIONS: " . $userMessage . "\n\nFILE CONTENT TO TRANSLATE:\n" . $textContent;

        // Use the shared clean translation context (no conversation history)
        $messages = [
            ['role' => 'system', 'content' => $fileTranslationPrompt],
            ['role' => 'user',   'content' => $fullContent],
        ];
        
        $result = $chatgpt->sendMessage($messages);
    }

    if (!$result || !$result['success']) {
        $error = $result['error'] ?? 'Unknown error';
        $logger->error('Translation API failed', ['error' => $error]);
        jsonResponse(500, [
            'error'      => 'عذراً، حدث خطأ أثناء ترجمة الملف. يرجى المحاولة مرة أخرى.',
            'session_id' => $sessionId,
        ]);
    }

    $reply = $result['message'];
    $sessions->addMessage($sessionId, 'assistant', $reply);

    jsonResponse(200, [
        'reply'      => $reply,
        'session_id' => $sessionId,
        'type'       => 'translation',
        'filename'   => $file['name'],
        'usage'      => $result['usage'] ?? [],
    ]);
}
// ==========================================
// END FILE UPLOADS
// ==========================================

if (empty(trim($userMessage))) {
    jsonResponse(400, ['error' => 'Message cannot be empty']);
}

// Check for prompt injection
if ($guard->detectInjection($userMessage)) {
    $errorMsg = detectLanguage($userMessage) === 'ar'
        ? 'أعتذر، لا أستطيع معالجة هذا الطلب. كيف يمكنني مساعدتك في البحث عن العروض أو الترجمة أو كتابة الإيميلات؟'
        : 'Sorry, I cannot process this request. How can I help you with finding offers, translation, or writing emails?';
    jsonResponse(200, [
        'reply'      => $errorMsg,
        'session_id' => $sessionId,
        'type'       => 'error',
    ]);
}

// Load system prompt
$systemPrompt = file_get_contents($config['bot']['system_prompt']);
if ($systemPrompt === false) {
    $logger->error('System prompt file not found');
    jsonResponse(500, ['error' => 'Internal server error']);
}

// Get or create session
$session   = $sessions->getSession($sessionId);
$sessionId = $session['id'];

// (Message will be added to session later after intent analysis)

// Analyze intent for potential offer search
$intent = $chatgpt->analyzeIntent($userMessage);

// If intent is offer_search, translate query to German and generate proper links
$enhancedMessage = $userMessage;
$userLang = detectLanguage($userMessage);

if ($intent === 'offer_search') {
    $userLang = detectLanguage($userMessage);
    $maxPrice = extractPrice($userMessage);

    // Step 1: Extract clean German product keyword
    $keyword = $chatgpt->extractProductKeyword($userMessage);
    $logger->info('Offer search triggered', [
        'original' => $userMessage,
        'keyword' => $keyword,
        'max_price' => $maxPrice
    ]);

    // Step 2: Get brand-specific search variants for better results
    $searchVariants = $chatgpt->getSearchVariants($keyword);
    $logger->info('Search variants', ['count' => count($searchVariants)]);

    // Step 3: Search local databases (Awin + idealo/geizhals scrapers)
    $searchResults = $search->search($keyword, $maxPrice, '', $searchVariants);
    $localResults = $searchResults['results'] ?? [];
    $logger->info('Local search completed', ['count' => count($localResults)]);

    // Step 4: Build the response data
    $linksText = '';
    $searchLinks = $search->generateSearchLinks($keyword, $maxPrice);

    // Format local results inline
    if (!empty($localResults)) {
        $linksText .= "\n--- VERIFIED PRODUCTS (from real databases) ---\n";
        foreach ($localResults as $i => $product) {
            $linksText .= "\n" . ($i + 1) . ". " . ($product['source_icon'] ?? '🛒') . " " . $product['title'] . "\n";
            $linksText .= "   Price: " . ($product['price_formatted'] ?? number_format($product['price'], 2, ',', '.') . ' €') . "\n";
            if (!empty($product['source'])) {
                $linksText .= "   Store: " . $product['source'] . "\n";
            }
            if (!empty($product['link'])) {
                $linksText .= "   Link: " . $product['link'] . "\n";
            }
            // Add alternative search links per product
            $encoded = urlencode($product['title']);
            $linksText .= "   Search: [Amazon](https://www.amazon.de/s?k={$encoded}) | [idealo](https://www.idealo.de/preisvergleich/MainSearchProductCategory.html?q={$encoded})\n";
        }
    }

    // Step 5: ALWAYS run web search to ensure real product results
    // Web search uses citations for real URLs — don't ask for structured URLs
    $logger->info('Running web search for product offers', ['keyword' => $keyword]);
    $priceFilter = $maxPrice ? " unter {$maxPrice} Euro" : "";
    
    $webPrompt = [
        [
            'role' => 'system',
            'content' => "You are a German product search assistant. Search for 5 of the best '{$keyword}'{$priceFilter} currently for sale in Germany.\n\n" .
                "For each product, write:\n" .
                "- The full product name (brand + model)\n" .
                "- The current price in EUR\n" .
                "- 2-3 key specifications\n" .
                "- Link to where you found it (use the actual source URL)\n\n" .
                "Only include REAL products you actually find in search results. Write in a simple numbered list format."
        ],
        ['role' => 'user', 'content' => "Suche 5 aktuelle Angebote für {$keyword}{$priceFilter} in Deutschland."]
    ];
    $webResult = $chatgpt->sendMessageWithWebSearch($webPrompt, $userLang);
    
    if ($webResult['success'] && !empty($webResult['message'])) {
        $logger->info('Web search returned product data', ['length' => strlen($webResult['message'])]);
        $linksText .= "\n--- PRODUCTS FOUND ONLINE ---\n";
        $linksText .= $webResult['message'];
        
        // Add GUARANTEED working search links per product found
        // Extract product names from the web search response and create search links
        $linksText .= "\n\n--- VERIFIED SEARCH LINKS PER PRODUCT ---\n";
        $linksText .= "For each product above, here are GUARANTEED working search links:\n";
        $linksText .= "• [Search '{$keyword}' on Amazon.de](https://www.amazon.de/s?k=" . urlencode($keyword) . ")\n";
        $linksText .= "• [Search '{$keyword}' on idealo.de](https://www.idealo.de/preisvergleich/MainSearchProductCategory.html?q=" . urlencode($keyword) . ")\n";
        $linksText .= "• [Search '{$keyword}' on MediaMarkt.de](https://www.mediamarkt.de/de/search.html?query=" . urlencode($keyword) . ")\n";
    } else {
        $logger->warning('Web search returned no results', [
            'error' => $webResult['error'] ?? 'empty'
        ]);
    }

    // Always add search links
    $linksText .= "\n\n--- SEARCH LINKS FOR MORE OPTIONS ---\n";
    foreach ($searchLinks as $sl) {
        $linksText .= "• {$sl['icon']} [{$sl['name']}]({$sl['url']})\n";
    }

    $linksText .= "\n🚨 RULES FOR YOUR REPLY:\n";
    if ($userLang === 'ar') {
        $linksText .= "1. يجب أن يكون ردك باللغة العربية حصراً.\n";
    } else {
        $linksText .= "1. Reply in the user's language.\n";
    }
    $linksText .= "2. Present the products above with their EXACT names, prices, and specs.\n";
    $linksText .= "3. For EACH product, include the Amazon/idealo/MediaMarkt SEARCH LINKS from the 'VERIFIED SEARCH LINKS' section. These are GUARANTEED to work.\n";
    $linksText .= "4. If a product has a source link from the web search, you MAY include it, but ALWAYS also include the search links.\n";
    $linksText .= "5. Always include the general search links section at the end for more options.\n";
    $linksText .= "6. 🚫 ABSOLUTELY NEVER invent product names, model numbers, prices, or URLs.\n";
    $linksText .= "7. 🚫 NEVER create fake links. Use ONLY the exact URLs provided above.\n";
    $linksText .= "8. ⚠️ Do NOT include the legal disclaimer for product offer responses.\n";

    $enhancedMessage = $userMessage . "\n\n[SYSTEM OFFER DATA — READ CAREFULLY]\n" . $linksText;
} elseif ($intent === 'contract_search') {
    $logger->info('Contract search triggered', ['original' => $userMessage]);
    
    $linksText  = "\n\n[SYSTEM NOTE — The user is looking for a service/subscription contract.]\n";
    $linksText .= "IMPORTANT INSTRUCTIONS FOR YOUR REPLY:\n";
    $linksText .= "1. You MUST provide the specific lak24.de category link from your SYSTEM PROMPT RULES.\n";
    if ($userLang === 'ar') {
        $linksText .= "2. يجب أن يكون ردك باللغة العربية حصراً.\n";
    } else {
        $linksText .= "2. You MUST reply in ENGLISH only.\n";
    }
    $linksText .= "3. Do NOT invent or hallucinate any other products or links. Do NOT suggest physical items.";
    
    $enhancedMessage = $userMessage . $linksText;
}

// Add user message to session (including search enhancements if any)
$sessions->addMessage($sessionId, 'user', $enhancedMessage);

// Prepare messages for API
$messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

// Send to GPT (streaming or regular)
if ($useStream) {
    // SSE Streaming response
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    ob_end_flush();

    // Send session ID first
    echo "data: " . json_encode(['type' => 'session', 'session_id' => $sessionId], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    $fullResponse = '';

    $result = $chatgpt->sendStreamingMessage($messages, function ($chunk, $done) use (&$fullResponse) {
        if ($done) {
            echo "data: " . json_encode(['type' => 'done'], JSON_UNESCAPED_UNICODE) . "\n\n";
        } else {
            $fullResponse .= $chunk;
            echo "data: " . json_encode(['type' => 'chunk', 'content' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        flush();
    });

    // Save assistant response to session
    if (!empty($fullResponse)) {
        $sessions->addMessage($sessionId, 'assistant', $fullResponse);
    }

    exit;
} else {
    // Regular response
    $result = $chatgpt->sendMessage($messages);

    if (!$result['success']) {
        $logger->error('Chat API failed', ['error' => $result['error']]);
        $apiErrorMsg = detectLanguage($userMessage) === 'ar'
            ? 'عذراً، حدث خطأ أثناء المعالجة. يرجى المحاولة مرة أخرى.'
            : 'Sorry, an error occurred during processing. Please try again.';
        jsonResponse(500, [
            'error'      => $apiErrorMsg,
            'session_id' => $sessionId,
        ]);
    }

    $reply = $result['message'];

    // Save assistant response to session
    $sessions->addMessage($sessionId, 'assistant', $reply);

    jsonResponse(200, [
        'reply'      => $reply,
        'session_id' => $sessionId,
        'type'       => $intent,
        'usage'      => $result['usage'],
    ]);
}

// ─── Helper Functions ────────────────────────────────────────────────

/**
 * Send JSON response and exit
 */
function jsonResponse(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Extract a price/budget from user message
 */
function extractPrice(string $message): ?float
{
    // Match patterns like: 500, 500€, 500 يورو, 500 euro, unter 500
    if (preg_match('/(\d+[\.,]?\d*)\s*(?:€|يورو|euro|eur)/iu', $message, $matches)) {
        return (float) str_replace(',', '.', $matches[1]);
    }

    // Match "أقل من 500" or "لا يزيد عن 500" patterns
    if (preg_match('/(?:أقل|اقل|لا يزيد|لا يتجاوز|بحد أقصى|بحدود|unter|bis|maximal|max)\s+(?:من|عن)?\s*(\d+[\.,]?\d*)/iu', $message, $matches)) {
        return (float) str_replace(',', '.', $matches[1]);
    }

    return null;
}

/**
 * Detect if message is primarily Arabic or Latin script
 * Used for bilingual error messages.
 */
function detectLanguage(string $message): string
{
    $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $message);
    $latinChars  = preg_match_all('/[a-zA-Z]/u', $message);
    
    if ($arabicChars > $latinChars) {
        return 'ar';
    }
    return 'en'; // Default to English for German and other Latin-script languages
}

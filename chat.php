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
    $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);
    $result = null;

    if ($processed['type'] === 'image') {
        // For images, add the placeholder and send as vision
        $sessions->addMessage($sessionId, 'user', "📄 تم رفع ملف (صورة): {$file['name']}\n{$userMessage}");
        $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);
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

        // Use the content ONLY for the current API call (No Storage policy)
        $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);
        $messages[] = ['role' => 'user', 'content' => $fullContent];
        
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
    // Extract price if mentioned
    $maxPrice = extractPrice($userMessage);

    // CRITICAL: Translate user's query to German product keywords
    // This prevents Arabic text from being used in Amazon/eBay URLs
    $germanKeyword = $chatgpt->extractProductKeyword($userMessage);
    $searchVariants = $chatgpt->getSearchVariants($germanKeyword);

    $logger->info('Offer search triggered', [
        'original'  => $userMessage,
        'keyword'   => $germanKeyword,
        'max_price' => $maxPrice,
        'variants'  => count($searchVariants)
    ]);

    // 1. Perform actual search (lak24, Amazon, Awin) using the GERMAN keyword
    $searchResults = $search->search($germanKeyword, $maxPrice, '', $searchVariants);
    
    // 2. Generate general search links using the GERMAN keyword
    $searchLinks = $search->generateSearchLinks($germanKeyword, $maxPrice);

    // Format the results for GPT to use
    $linksText  = "\n\n[SYSTEM OFFER DATA — READ CAREFULLY]\n";
    $linksText .= "Search keyword: {$germanKeyword}\n";
    if ($maxPrice !== null) {
        $linksText .= "Max budget: {$maxPrice} EUR\n";
    }

    $hasRealProducts = !empty($searchResults['results']);

    if ($hasRealProducts) {
        // We have REAL product data — present it
        $linksText .= "\n--- VERIFIED PRODUCTS (from real databases) ---\n";
        $linksText .= $search->formatResultsForBot($searchResults);
        $linksText .= "\nRULES: Present ONLY these products above. Each has a real title, price, and link.\n";
        $linksText .= "You may rephrase the titles but NEVER change the prices or links.\n";
    } else {
        // NO local results — try Web Search Fallback
        $logger->info('No local results, trying web search fallback', ['keyword' => $germanKeyword]);
        
        // Build a focused search prompt (NOT the full conversation — that causes hallucination)
        $priceFilter = $maxPrice ? " unter {$maxPrice} Euro" : "";
        $webSearchPrompt = [
            [
                'role' => 'system',
                'content' => "You are a product search assistant for Germany. Search the web and return ONLY real products you find. For each product, include: product name, price in EUR, store name, and the EXACT URL. Format as a numbered list. Do NOT invent any products. If you cannot find real products, say 'Keine Produkte gefunden'. Reply in plain text, no markdown."
            ],
            [
                'role' => 'user',
                'content' => "Suche: {$germanKeyword}{$priceFilter} kaufen in Deutschland. Finde 3-5 aktuelle Angebote mit echten Preisen und Links."
            ]
        ];
        
        $webResult = $chatgpt->sendMessageWithWebSearch($webSearchPrompt, $userLang);
        
        if ($webResult['success'] && !empty(trim($webResult['message']))) {
            $logger->info('Web search returned results', ['length' => strlen($webResult['message'])]);
            $linksText .= "\n--- PRODUCTS FOUND VIA LIVE WEB SEARCH ---\n";
            $linksText .= $webResult['message'] . "\n";
            $linksText .= "\nRULES: Present these web search results to the user. These are REAL products found online just now.\n";
            $linksText .= "Use the EXACT product names, prices, and URLs from above. Do NOT modify or invent new ones.\n";
        } else {
            $logger->warning('Web search also returned no results', ['error' => $webResult['error'] ?? 'empty']);
            $linksText .= "\n--- NO PRODUCTS FOUND (database and web search both returned empty) ---\n";
            $linksText .= "Tell the user no specific products were found, but they can browse the search links below.\n";
        }
    }

    $linksText .= "\n--- REAL SEARCH LINKS (verified, working URLs) ---\n";
    foreach ($searchLinks as $link) {
        $linksText .= "• {$link['icon']} [{$link['name']}]({$link['url']})\n";
    }

    $linksText .= "\n🚨 STRICT RULES FOR YOUR REPLY:\n";
    if ($userLang === 'ar') {
        $linksText .= "1. يجب أن يكون ردك باللغة العربية حصراً.\n";
    } else {
        $linksText .= "1. Reply in the user's language.\n";
    }

    if ($hasRealProducts) {
        $linksText .= "2. Present the verified products above with their EXACT prices and links.\n";
        $linksText .= "3. Then present the search links so the user can browse more options.\n";
        $linksText .= "4. Highlight the best value option.\n";
    } else {
        $linksText .= "2. Present the products from the data above (if any were found via web search) with their EXACT names, prices, and links.\n";
        $linksText .= "3. Also present the search links so the user can browse more options.\n";
        $linksText .= "4. If products were found, highlight the best value option.\n";
    }

    $linksText .= "5. 🚫 ABSOLUTELY NEVER invent product names, model numbers, prices, or URLs. This is FORBIDDEN.\n";
    $linksText .= "6. 🚫 NEVER create fake links. Use ONLY the exact URLs provided in this data block.\n";
    $linksText .= "7. Copy-paste URLs exactly as provided above.";

    $enhancedMessage = $userMessage . $linksText;
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

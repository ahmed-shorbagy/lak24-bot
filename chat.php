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

    // --- START ROBUST OFFER SEARCH ---
    
    // Step 1: Query Refinement (Thinking like a professional German shopper)
    $refinementPrompt = [
        ['role' => 'system', 'content' => "Refine the user's product query into a professional German search term for a price comparison site like idealo or geizhals.\n" .
            "- Be specific to avoid accessories or mini-versions.\n" .
            "- Examples:\n" .
            "  - 'fridge' -> 'Kühl-Gefrierkombination freistehend'\n" .
            "  - 'office chair' -> 'Bürostuhl ergonomisch'\n" .
            "  - 'laptop' -> 'Notebook Laptop'\n" .
            "Output ONLY the refined German search term."],
        ['role' => 'user', 'content' => "Translate and refine: {$userMessage}"]
    ];
    $refinedResult = $chatgpt->sendMessage($refinementPrompt, $userLang);
    $searchQuery = !empty($refinedResult['message']) ? trim($refinedResult['message']) : $germanKeyword;
    $logger->info('Refined search query', ['original' => $userMessage, 'refined' => $searchQuery]);

    // Step 2: Primary Search (Local Awin + Scrapers)
    $searchData = $offerSearch->search($searchQuery, $maxPrice);
    $allResults = $searchData['results'] ?? [];
    $searchLinks = $searchData['search_links'] ?? [];

    // Step 3: AI-Driven Deep Search (The "Robust" Part) - Fetch high-quality direct links
    // Run if we have fewer than 3 results from local/scraper
    if (count($allResults) < 3) {
        $logger->info('Insufficient results from local/scraper, trying Deep Web Search');
        $priceFilter = $maxPrice ? " unter {$maxPrice} Euro" : "";
        $deepPrompt = [
            [
                'role' => 'system',
                'content' => "Find EXACTLY 3 high-quality product offers for '{$searchQuery}' in Germany{$priceFilter}.\n" .
                    "Focus on finding DIRECT product page URLs from trusted stores like: Amazon.de, MediaMarkt.de, Saturn.de, Otto.de, or Idealo.de.\n\n" .
                    "OUTPUT FORMAT — for each product, output EXACTLY this:\n" .
                    "PRODUCT: [full product name + model]\n" .
                    "PRICE: [price in EUR, numbers only]\n" .
                    "SPECS: [key specs]\n" .
                    "URL: [direct product page URL]\n" .
                    "---"
            ],
            ['role' => 'user', 'content' => "Finde 3 aktuelle Angebote für {$searchQuery}{$priceFilter} zum Kaufen in Deutschland."]
        ];
        $deepResult = $chatgpt->sendMessageWithWebSearch($deepPrompt, $userLang);
        
        if ($deepResult['success'] && !empty($deepResult['message'])) {
            $productBlocks = preg_split('/---\s*/', $deepResult['message']);
            foreach ($productBlocks as $block) {
                $block = trim($block);
                if (empty($block)) continue;
                preg_match('/PRODUCT:\s*(.+)/i', $block, $n);
                preg_match('/PRICE:\s*(.+)/i', $block, $p);
                preg_match('/SPECS:\s*(.+)/i', $block, $s);
                preg_match('/URL:\s*(.+)/i', $block, $u);
                
                if (!empty($n[1])) {
                    $allResults[] = [
                        'title' => trim($n[1]),
                        'price' => isset($p[1]) ? (float)preg_replace('/[^\d.]/', '', $p[1]) : 0,
                        'price_formatted' => (isset($p[1]) ? trim($p[1]) : 'Check link') . ' €',
                        'specs' => isset($s[1]) ? trim($s[1]) : '',
                        'link' => trim($u[1] ?? ''),
                        'source' => 'Web Search',
                        'source_icon' => '🌐'
                    ];
                }
            }
        }
    }

    // Step 4: Hybrid Formatting & URL Validation
    $trustedDomains = ['amazon.de', 'idealo.de', 'geizhals.de', 'mediamarkt.de', 'saturn.de', 'otto.de', 'coolblue.de', 'notebooksbilliger.de'];
    $linksText = "\n--- TOP OFFERS FOUND ---\n";
    
    if (empty($allResults)) {
        $linksText .= "NO specific products found. Use the search links below.\n";
    }

    foreach ($allResults as $i => $res) {
        $validLink = false;
        if (!empty($res['link']) && filter_var($res['link'], FILTER_VALIDATE_URL)) {
            $host = parse_url($res['link'], PHP_URL_HOST);
            if ($host) {
                foreach ($trustedDomains as $d) {
                    if (strpos($host, $d) !== false) { $validLink = true; break; }
                }
            }
        }
        
        $encoded = urlencode($res['title']);
        $linksText .= "\n" . ($i+1) . ". " . ($res['source_icon'] ?? '🛒') . " " . $res['title'] . "\n";
        $linksText .= "   Price: " . ($res['price_formatted'] ?? $res['price'] . ' €') . "\n";
        if (!empty($res['specs'])) $linksText .= "   Specs: " . $res['specs'] . "\n";
        
        if ($validLink) {
            $linksText .= "   Direct Link: [View Offer](" . $res['link'] . ")\n";
        }
        $linksText .= "   Alternative Search: [Amazon](https://www.amazon.de/s?k={$encoded}) | [idealo](https://www.idealo.de/preisvergleich/MainSearchProductCategory.html?q={$encoded})\n";
    }

    $linksText .= "\n--- SEARCH LINKS FOR RESEARCH ---\n";
    foreach ($searchLinks as $sl) {
        $linksText .= "• {$sl['icon']} [{$sl['name']}]({$sl['url']})\n";
    }

    $linksText .= "\n🚨 RULES FOR YOUR REPLY:\n";
    if ($userLang === 'ar') {
        $linksText .= "1. يجب أن يكون ردك باللغة العربية حصراً.\n";
    } else {
        $linksText .= "1. Reply in the user's language.\n";
    }
    $linksText .= "2. Present the products above with their EXACT names, prices, and links.\n";
    $linksText .= "3. If a product has a 'Direct Link', prioritize it. If not, present the 'Search Links' for that product.\n";
    $linksText .= "4. Highlight the 'Best Value' product based on its specifications and price.\n";
    $linksText .= "5. 🚫 ABSOLUTELY NEVER invent product names, model numbers, prices, or URLs. This is FORBIDDEN.\n";
    $linksText .= "6. 🚫 NEVER create fake links. Use ONLY the exact URLs provided above.\n";
    $linksText .= "7. ⚠️ Do NOT include the legal disclaimer for product offer responses.\n";

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

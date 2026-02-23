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

// Apply CORS
$guard->setCORSHeaders($config['api']['cors']);

// Rate limiting
$rateCheck = $guard->checkRateLimit();
if (!$rateCheck['allowed']) {
    jsonResponse(429, [
        'error'    => detectLanguage($input['message'] ?? '') === 'ar' 
            ? 'تم تجاوز الحد الأقصى للطلبات. يرجى المحاولة بعد قليل.'
            : 'Too many requests. Please try again shortly.',
        'reset_at' => $rateCheck['reset_at'],
    ]);
}

// Parse request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['message'])) {
    jsonResponse(400, ['error' => 'Message is required']);
}

$userMessage = $guard->sanitizeInput($input['message']);
$sessionId   = $input['session_id'] ?? null;
$useStream   = $input['stream'] ?? false;

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

// Add user message to session
$sessions->addMessage($sessionId, 'user', $userMessage);

// Analyze intent for potential offer search
$intent = $chatgpt->analyzeIntent($userMessage);

// If intent is offer_search, translate query to German and generate proper links
$enhancedMessage = $userMessage;
if ($intent === 'offer_search') {
    // Extract price if mentioned
    $maxPrice = extractPrice($userMessage);

    // CRITICAL: Translate user's query to German product keywords
    // This prevents Arabic text from being used in Amazon/eBay URLs
    $germanKeyword = $chatgpt->extractProductKeyword($userMessage);

    $logger->info('Offer search triggered', [
        'original'  => $userMessage,
        'keyword'   => $germanKeyword,
        'max_price' => $maxPrice
    ]);

    // 1. Perform actual search (lak24, Amazon, Awin) using the GERMAN keyword
    $searchResults = $search->search($germanKeyword, $maxPrice);
    
    // 2. Generate general search links using the GERMAN keyword
    $searchLinks = $search->generateSearchLinks($germanKeyword, $maxPrice);

    // Format the results for GPT to use
    // IMPORTANT: Use English for internal instructions to avoid biasing GPT's response language
    $linksText  = "\n\n[SYSTEM NOTE — Use ONLY these real results/links. Do NOT invent any others.]\n";
    $linksText .= "Search keyword used: {$germanKeyword}\n";
    if ($maxPrice !== null) {
        $linksText .= "Max budget: {$maxPrice} EUR\n";
    }

    if (!empty($searchResults['results'])) {
        $linksText .= "\nFound specific product matches:\n";
        $linksText .= $search->formatResultsForBot($searchResults);
    }

    $linksText .= "\nDirect search category links:\n";
    foreach ($searchLinks as $link) {
        $linksText .= "• {$link['icon']} [{$link['name']}]({$link['url']})\n";
    }

    $linksText .= "\nIMPORTANT INSTRUCTIONS FOR YOUR REPLY:\n";
    $linksText .= "1. You MUST reply in the EXACT SAME LANGUAGE the user wrote their message in.\n";
    $linksText .= "2. Present the specific products found (if any) and the direct search links clearly.\n";
    $linksText .= "3. Highlight the BEST VALUE option (cheapest or best deal). If a product exceeds the user's budget, warn them.\n";
    $linksText .= "4. If NO specific products were found, suggest alternative search terms or related categories — never just say 'no results'.\n";
    $linksText .= "5. Do NOT invent or hallucinate any other products or links.";

    $enhancedMessage = $userMessage . $linksText;
} elseif ($intent === 'contract_search') {
    $logger->info('Contract search triggered', ['original' => $userMessage]);
    
    $linksText  = "\n\n[SYSTEM NOTE — The user is looking for a service/subscription contract (e.g. Internet, Electricity, Gas, Travel, Insurance).]\n";
    $linksText .= "IMPORTANT INSTRUCTIONS FOR YOUR REPLY:\n";
    $linksText .= "1. You MUST provide the specific lak24.de category link from your SYSTEM PROMPT RULES (e.g. category_id=82 for Electricity or 121 for Travel).\n";
    $linksText .= "2. You MUST reply in the EXACT SAME LANGUAGE the user wrote their message in. If the user wrote in English, reply in English. If Arabic, reply in Arabic.\n";
    $linksText .= "3. Do NOT invent or hallucinate any other products or links. Do NOT suggest physical items.";
    
    $enhancedMessage = $userMessage . $linksText;
}

// Prepare messages for API
$messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

// Replace last user message with enhanced version if we have search results
if ($enhancedMessage !== $userMessage) {
    $lastIdx = count($messages) - 1;
    $messages[$lastIdx] = ['role' => 'user', 'content' => $enhancedMessage];
}

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

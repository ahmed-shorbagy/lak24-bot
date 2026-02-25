<?php

// RAW DEBUG LOGGER (works even if Logger class fails)
$rawDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/Chatbot/logs';
if (!is_dir($rawDir)) { @mkdir($rawDir, 0755, true); }

$rawFile = $rawDir . '/raw_chat_debug_' . date('Y-m-d') . '.log';

@file_put_contents($rawFile, "[".date('c')."] CHAT.PHP START\n", FILE_APPEND);

register_shutdown_function(function() use ($rawFile){
  $e = error_get_last();
  if ($e) {
    @file_put_contents(
      $rawFile,
      "[".date('c')."] FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n",
      FILE_APPEND
    );
  }
});
  
  
/**
 * lak24 AI Chatbot — Main Chat Endpoint (Improved Routing + Cost Control)
 *
 * POST /chat.php
 * Supports:
 * - Text chat
 * - File uploads (pdf/images)
 * - Optional streaming (SSE)
 */

define('LAK24_BOT', true);

$dbg = __DIR__ . '/logs/upload_debug_' . date('Y-m-d') . '.log';
@file_put_contents($dbg,
  "[".date('c')."] CT=".($_SERVER['CONTENT_TYPE'] ?? '').
  " FILES=".json_encode(array_keys($_FILES)).
  " FILEERR=".($_FILES['file']['error'] ?? 'no-file').
  " POST=".json_encode(array_keys($_POST)).
  "\n",
  FILE_APPEND
);

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

// OfferSearch uses cache + logger
$search   = new OfferSearch(
    array_merge($config['search'], ['affiliate' => $config['affiliate'] ?? []]),
    $cache,
    $logger
);

// Security headers
$guard->setSecurityHeaders();

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $guard->setCORSHeaders($config['api']['cors']);
    http_response_code(204);
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['error' => 'Method not allowed']);
}

// Parse input (JSON or form-data)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input && !empty($_POST)) $input = $_POST;

$hasFile      = !empty($_FILES['file']);
$userMessage  = $input['message'] ?? '';
$userMessage  = $guard->sanitizeInput($userMessage);
$sessionId    = $input['session_id'] ?? null;
$useStream    = (bool)($input['stream'] ?? false);

if (!$input && !$hasFile) {
    jsonResponse(400, ['error' => 'Message or file is required']);
}
if (!$hasFile && empty(trim($userMessage))) {
    jsonResponse(400, ['error' => 'Message is required when no file is uploaded']);
}

// Rate limiting
$rateCheck = $guard->checkRateLimit();
if (!$rateCheck['allowed']) {
    jsonResponse(429, [
        'error'    => detectLanguage($userMessage) === 'ar'
            ? 'تم تجاوز الحد الأقصى للطلبات. يرجى المحاولة بعد قليل.'
            : 'Too many requests. Please try again shortly.',
        'reset_at' => $rateCheck['reset_at'],
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

/**
 * 1) File Upload Handling (translation intent by default)
 */
if ($hasFile) {
    $file      = $_FILES['file'];
    $processor = new FileProcessor($config['upload'], $logger);

    $validation = $guard->validateUpload($file, $config['upload']);
    if (!$validation['valid']) {
        jsonResponse(400, ['error' => $validation['error']]);
    }

    $processed = $processor->processUpload($file);
    if (!$processed['success']) {
        $logger->error('File processing failed', [
            'filename' => $file['name'],
            'error'    => $processed['error'],
        ]);
        jsonResponse(400, ['error' => $processed['error']]);
    }

    // Default translation instruction if user did not write any message
    if (empty(trim($userMessage))) {
        $userMessage = 'ترجم المحتوى التالي من/إلى الألمانية حسب اللغة المكتشفة. اعرض النص الأصلي أولاً ثم الترجمة.';
    }

    // Enforce scope: file uploads are only allowed for translation use-case
    $intent = 'translate';
    if (!isAllowedIntent($intent, $config['bot']['allowed_intents'] ?? [])) {
        jsonResponse(200, [
            'reply'      => scopeRefusal($userMessage),
            'session_id' => $sessionId,
            'type'       => 'refusal',
        ]);
    }

    // Choose model & token limit for translation (files are often heavier)
    [$model, $maxTokens, $temperature] = chooseModelAndLimits($intent, $userMessage, true, $config);

    // Create per-request ChatGPT client with overrides
    $openaiCfg = $config['openai'];
    $openaiCfg['model'] = $model;
    $openaiCfg['max_tokens'] = $maxTokens;

    // ====== FIX (ONLY TEMPERATURE HANDLING) ======
    // Ensure config temperature never leaks into GPT-5 calls.
    unset($openaiCfg['temperature']);
    if (str_starts_with($model, 'gpt-5')) {
        $openaiCfg['temperature'] = 1;     // GPT-5 only supports default=1
    } else {
        $openaiCfg['temperature'] = $temperature;
    }
    // ============================================

    $chatgpt = new ChatGPT($openaiCfg, $logger);

    // Add message to session
    $sessions->addMessage($sessionId, 'user', "📄 تم رفع ملف: {$file['name']}\n{$userMessage}");

    $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);
    $result   = null;

    if ($processed['type'] === 'image') {
        // Vision
        $result = $chatgpt->sendVisionMessage(
            $messages,
            $processed['data'],
            $processed['mime'],
            $userMessage
        );
    } elseif ($processed['type'] === 'text') {
        // PDF extracted text
        $textContent = $processed['data'];
        if (mb_strlen($textContent) > 15000) {
            $textContent = mb_substr($textContent, 0, 15000) . "\n\n[... تم اقتطاع النص بسبب الطول]";
        }
        $messages[] = [
            'role'    => 'user',
            'content' => $userMessage . "\n\n---\n\n" . $textContent,
        ];
        $result = $chatgpt->sendMessage($messages);
    }

    if (!$result || empty($result['success'])) {
        $logger->error('File translation failed', ['error' => $result['error'] ?? 'Unknown error']);
        jsonResponse(500, [
            'error'      => detectLanguage($userMessage) === 'ar'
                ? 'عذراً، حدث خطأ أثناء ترجمة الملف. يرجى المحاولة مرة أخرى.'
                : 'Sorry, an error occurred while translating the file. Please try again.',
            'session_id' => $sessionId,
        ]);
    }

    $reply = $result['message'];
    $sessions->addMessage($sessionId, 'assistant', $reply);

    jsonResponse(200, [
        'reply'      => $reply,
        'session_id' => $sessionId,
        'type'       => $intent,
        'filename'   => $file['name'],
        'model'      => $model,
        'usage'      => $result['usage'] ?? [],
    ]);
}

/**
 * 2) Text message handling
 */

// Prompt injection check
if ($guard->detectInjection($userMessage)) {
    jsonResponse(200, [
        'reply'      => detectLanguage($userMessage) === 'ar'
            ? 'أعتذر، لا أستطيع معالجة هذا الطلب. يمكنني مساعدتك في: العروض، الترجمة، كتابة الرسائل بالألمانية، تعبئة النماذج، وأسئلة الحياة في ألمانيا.'
            : 'Sorry, I cannot process this request. I can help with: deals, translation, German writing, forms, and life in Germany.',
        'session_id' => $sessionId,
        'type'       => 'refusal',
    ]);
}

// Detect intent (simple, reliable, cheap)
$intent = detectIntent($userMessage);

// Enforce bot scope BEFORE calling OpenAI
if (!isAllowedIntent($intent, $config['bot']['allowed_intents'] ?? [])) {
    jsonResponse(200, [
        'reply'      => scopeRefusal($userMessage),
        'session_id' => $sessionId,
        'type'       => 'refusal',
    ]);
}

// Add user message
$sessions->addMessage($sessionId, 'user', $userMessage);

// If deals intent: do real search and inject results/links
$enhancedMessage = $userMessage;
if ($intent === 'deals') {
    $maxPrice = extractPrice($userMessage);

    // Use GPT-based keyword extraction for better German search terms
    // (translates Arabic/English queries to clean German product nouns)
    $tempCfg = $config['openai'];
    $tempCfg['model'] = $config['openai']['routing']['light'] ?? 'gpt-4o-mini';
    unset($tempCfg['temperature']);
    $tempCfg['temperature'] = 0.0;
    $keywordExtractor = new ChatGPT($tempCfg, $logger);
    $keyword = $keywordExtractor->extractProductKeyword($userMessage);

    // Fallback if extraction returns empty
    if (empty(trim($keyword))) {
        $keyword = extractDealKeyword($userMessage);
    }

    $logger->info('Deals search triggered', [
        'original'  => $userMessage,
        'keyword'   => $keyword,
        'max_price' => $maxPrice,
    ]);

    $searchResults = $search->search($keyword, $maxPrice);
    $searchLinks   = $search->generateSearchLinks($keyword, $maxPrice);

    $linksText  = "\n\n[SYSTEM NOTE — CRITICAL: Use ONLY the real product links below. NEVER invent product names, prices, or URLs.]\n";
    $linksText .= "Search keyword used: {$keyword}\n";
    if ($maxPrice !== null) $linksText .= "Max budget: {$maxPrice} EUR\n";

    // === DIRECT PRODUCT LINKS (priority) ===
    if (!empty($searchResults['results'])) {
        $linksText .= "\n══════ DIRECT PRODUCT OFFERS (USE THESE FIRST) ══════\n";
        $linksText .= $search->formatResultsForBot($searchResults);
        $linksText .= "══════════════════════════════════════════════════════\n";
    }

    // === SEARCH LINKS (secondary, "browse more") ===
    $linksText .= "\n── Browse more (show these as secondary links at the end) ──\n";
    foreach ($searchLinks as $link) {
        $linksText .= "• {$link['icon']} [{$link['name']}]({$link['url']})\n";
    }

    $linksText .= "\nIMPORTANT INSTRUCTIONS FOR YOUR REPLY:\n";
    $linksText .= "1) You MUST reply entirely in the SAME language the user used.\n";
    $linksText .= "2) You MUST translate all German product titles, descriptions, and details from the offers into the user's language.\n";
    $linksText .= "3) Organize the DIRECT PRODUCT OFFERS using a clean, structured list.\n";
    $linksText .= "4) CRITICAL: You MUST include the 'Direct Link' for EVERY product you list. Make the product name a clickable Markdown hyperlink pointing to its Direct Link! Example: **[Product Name Here](https://www.awin1.com/...)**\n";
    $linksText .= "5) Do NOT print the raw URL text. Always hide it behind the Markdown hyperlink on the product name.\n";
    $linksText .= "6) Maintain a professional and easy-to-read structure (use bullet points and bold text for merchant names and prices). Every product MUST have its link!\n";
    $linksText .= "7) At the END of your reply, add a '🔍 Browse more:' section with the search links.\n";
    $linksText .= "8) Do NOT invent products, prices, or links. Use ONLY what is provided above.\n";
    $linksText .= "9) NEVER apologize, NEVER say 'I don't have X', and NEVER mention 'my list' or 'search results'. Always confidently present the provided offers as the best available options.\n";

    $enhancedMessage = $userMessage . $linksText;
}

// Choose model and max_tokens based on intent + complexity
[$model, $maxTokens, $temperature] = chooseModelAndLimits($intent, $userMessage, false, $config);

// Create ChatGPT client with overrides
$openaiCfg = $config['openai'];
$openaiCfg['model'] = $model;
$openaiCfg['max_tokens'] = $maxTokens;

// ====== FIX (ONLY TEMPERATURE HANDLING) ======
unset($openaiCfg['temperature']);
if (str_starts_with($model, 'gpt-5')) {
    $openaiCfg['temperature'] = 1;
} else {
    $openaiCfg['temperature'] = $temperature;
}
// ============================================

$chatgpt = new ChatGPT($openaiCfg, $logger);

// Prepare messages for API
$messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

// Replace last user message with enhanced version (deals)
if ($enhancedMessage !== $userMessage) {
    $lastIdx = count($messages) - 1;
    $messages[$lastIdx] = ['role' => 'user', 'content' => $enhancedMessage];
}

// Streaming or normal
if ($useStream) {
    // SSE
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    @ob_end_flush();

    echo "data: " . json_encode(['type' => 'session', 'session_id' => $sessionId], JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    $fullResponse = '';

    // If your ChatGPT class supports streaming:
    $result = $chatgpt->sendStreamingMessage($messages, function ($chunk, $done) use (&$fullResponse) {
        if ($done) {
            echo "data: " . json_encode(['type' => 'done'], JSON_UNESCAPED_UNICODE) . "\n\n";
        } else {
            $fullResponse .= $chunk;
            echo "data: " . json_encode(['type' => 'chunk', 'content' => $chunk], JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        flush();
    });

    if (!empty($fullResponse)) {
        $sessions->addMessage($sessionId, 'assistant', $fullResponse);
    }

    exit;
}

// Regular response
$result = $chatgpt->sendMessage($messages);

// Fallback escalation if needed (cheap → better) for sensitive/weak outputs
if (empty($result['success'])) {
    $logger->error('Chat API failed', ['error' => $result['error'] ?? 'Unknown error']);
    jsonResponse(500, [
        'error'      => detectLanguage($userMessage) === 'ar'
            ? 'عذراً، حدث خطأ أثناء المعالجة. يرجى المحاولة مرة أخرى.'
            : 'Sorry, an error occurred during processing. Please try again.',
        'session_id' => $sessionId,
    ]);
}

$reply = $result['message'] ?? '';

if (shouldEscalate($intent, $userMessage, $reply, $model, $config)) {
    $logger->info('Escalating model due to weak/unsafe response', [
        'from' => $model,
        'to'   => $config['openai']['routing']['heavy'] ?? 'gpt-5.1',
        'intent' => $intent,
    ]);

    $heavyModel = $config['openai']['routing']['heavy'] ?? 'gpt-5.1';
    $openaiCfg2 = $config['openai'];
    $openaiCfg2['model'] = $heavyModel;
    $openaiCfg2['max_tokens'] = max($maxTokens, (int)($config['openai']['limits'][$intent] ?? $maxTokens));

    // ====== FIX (ONLY TEMPERATURE HANDLING) ======
    unset($openaiCfg2['temperature']);
    if (str_starts_with($heavyModel, 'gpt-5')) {
        $openaiCfg2['temperature'] = 1;
    } else {
        $openaiCfg2['temperature'] = 0.2;
    }
    // ============================================

    $chatgpt2 = new ChatGPT($openaiCfg2, $logger);
    $result2  = $chatgpt2->sendMessage($messages);

    if (!empty($result2['success']) && !empty($result2['message'])) {
        $result = $result2;
        $reply  = $result2['message'];
        $model  = $heavyModel;
    }
}

// Save assistant response
$sessions->addMessage($sessionId, 'assistant', $reply);

jsonResponse(200, [
    'reply'      => $reply,
    'session_id' => $sessionId,
    'type'       => $intent,
    'model'      => $model,
    'usage'      => $result['usage'] ?? [],
]);

/**
 * ─────────────────────────────────────────────────────────────
 * Helper functions
 * ─────────────────────────────────────────────────────────────
 */

function jsonResponse(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function detectLanguage(string $message): string {
    $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $message);
    $latinChars  = preg_match_all('/[a-zA-Z]/u', $message);
    return ($arabicChars > $latinChars) ? 'ar' : 'en';
}

/**
 * Extract budget
 */
function extractPrice(string $message): ?float {
    if (preg_match('/(\d+[\.,]?\d*)\s*(?:€|يورو|euro|eur)/iu', $message, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }
    if (preg_match('/(?:أقل|اقل|لا يزيد|لا يتجاوز|بحد أقصى|unter|bis|maximal|max)\s+(?:من|عن)?\s*(\d+[\.,]?\d*)/iu', $message, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }
    return null;
}

/**
 * Detect user intent (strict scope)
 * Returns: deals | translate | writing | forms | life_de | other
 */
function detectIntent(string $text): string {
    $t = mb_strtolower($text);

    // translation
    if (preg_match('/\b(ترجم|ترجمة|ترجمةً|übersetz|uebersetz|translate|translation)\b/u', $t)) {
        return 'translate';
    }

    // writing german docs
    if (preg_match('/\b(anschreiben|lebenslauf|cv|bewerbung|kündigung|kuendigung|email|e-mail|antwort|reply|اكتب|ايميل|رسالة|سيرة ذاتية|write|letter)\b/u', $t)) {
        return 'writing';
    }

    // forms / anträge
    if (preg_match('/\b(antrag|anträge|antraege|formular|jobcenter|wohngeld|kinderzuschlag|elterngeld|bürgergeld|ausländerbehörde|anmeldung|تعبئة|استمارة|نموذج|form|fill)\b/u', $t)) {
        return 'forms';
    }

    // deals / offers
    if (preg_match('/\b(عرض|عروض|deal|deals|offer|offers|best|angebot|angebote|rabatt|خفض|خصم|laptop|notebook|لابتوب|preis|unter|bis|eur|€)\b/u', $t)) {
        return 'deals';
    }

    // life in germany
    if (preg_match('/\b(ألمانيا|المانيا|المانية|الالمانية|deutschland|germany|german'
        // German cities (Latin + Arabic transliterations)
        . '|berlin|برلين|hamburg|هامبورج|هامبورغ|münchen|munich|ميونخ|ميونيخ'
        . '|frankfurt|فرانكفورت|köln|كولن|كولونيا|düsseldorf|دوسلدورف|stuttgart|شتوتغارت'
        . '|hannover|هانوفر|bremen|بريمن|dresden|دريسدن|leipzig|لايبزيغ|nürnberg|نورنبرغ'
        . '|dortmund|دورتموند|essen|إيسن|bonn|بون|aachen|آخن|freiburg|فرايبورغ'
        // Geography / location words (Arabic)
        . '|اين|أين|تقع|مدينة|ولاية|مقاطعة|منطقة'
        // Common life-in-Germany topics
        . '|visa|فيزا|إقامة|اقامة|سكن|تأمين|عمل|job|live|arbeiten|wohnen|studieren'
        . '|aufenthalt|niederlassung|einbürgerung|جنسية|تجنس|لجوء|asyl'
        . '|schule|مدرسة|جامعة|universität|kindergeld|kindergarten'
        . ')\b/u', $t)) {
        return 'life_de';
    }

    return 'other';
}

/**
 * Allowed intent enforcement
 */
function isAllowedIntent(string $intent, array $allowed): bool {
    return in_array($intent, $allowed, true);
}

function scopeRefusal(string $userMessage): string {
    $lang = detectLanguage($userMessage);
    if ($lang === 'ar') {
        return "عذرًا، هذا البوت يجيب فقط على: (1) العروض مع روابط، (2) الترجمة من/إلى الألمانية، (3) كتابة رسائل/سيرة/Anschreiben/Kündigung بالألمانية، (4) المساعدة في تعبئة Anträge، (5) أسئلة الحياة في ألمانيا.";
    }
    return "Sorry — this bot only handles: (1) deals with links, (2) German translation, (3) German writing (CV/Anschreiben/Kündigung), (4) filling German forms (Anträge), (5) life in Germany questions.";
}

/**
 * Choose model + max_tokens based on config routing/limits + complexity.
 * Returns [model, maxTokens, temperature]
 */
function chooseModelAndLimits(string $intent, string $userText, bool $hasFile, array $config): array {
    $routing = $config['openai']['routing'] ?? [
        'light' => 'gpt-4o-mini',
        'default' => 'gpt-5-mini',
        'heavy' => 'gpt-5.1',
    ];

    $limits  = $config['openai']['limits'] ?? [];
    $defaultMax = (int)($config['openai']['max_tokens'] ?? 900);

    $t = mb_strtolower($userText);
    $wordCount = str_word_count($userText);

    // Sensitive keywords -> heavy
    $sensitive = preg_match('/\b(kündigung|kuendigung|abmahnung|widerspruch|klage|anwalt|frist|vertrag|behörde|jobcenter|ausländerbehörde|bürgergeld|paragraf)\b/u', $t);

    // Light criteria
    $isLight = (!$hasFile && $wordCount < 60 && in_array($intent, ['life_de'], true));

    // Heavy criteria
    $isHeavy = ($hasFile && $intent === 'translate') || $sensitive || ($intent === 'forms' && $wordCount > 200) || ($intent === 'writing' && $wordCount > 220);

    $model = $routing['default'] ?? 'gpt-5-mini';
    if ($isHeavy) $model = $routing['heavy'] ?? 'gpt-5.1';
    elseif ($isLight) $model = $routing['light'] ?? 'gpt-4o-mini';

    // Per-intent token limits
    $maxTokens = (int)($limits[$intent] ?? $limits['default'] ?? $defaultMax);

    // Temperature: stable defaults for your use case
    $temperature = 0.2;
    if ($intent === 'life_de' && $isLight) $temperature = 0.3; // small flexibility
    if ($intent === 'deals') $temperature = 0.2;

    return [$model, $maxTokens, $temperature];
}

/**
 * Decide if we should escalate to heavy model
 */
function shouldEscalate(string $intent, string $userText, string $reply, string $model, array $config): bool {
    $routing = $config['openai']['routing'] ?? [];
    $heavy = $routing['heavy'] ?? 'gpt-5.1';
    if ($model === $heavy) return false;

    // If reply is too short for tasks that require structure
    if (in_array($intent, ['writing', 'forms', 'deals', 'translate'], true) && mb_strlen(trim($reply)) < 200) {
        return true;
    }

    // If it looks like refusal or uncertainty in a wrong place
    $r = mb_strtolower($reply);
    if (preg_match('/\b(ربما|قد|غير متأكد|لا أستطيع|i am not sure|maybe|might)\b/u', $r) && in_array($intent, ['writing','forms','translate'], true)) {
        return true;
    }

    // Sensitive keywords in user text
    $t = mb_strtolower($userText);
    if (preg_match('/\b(kündigung|kuendigung|abmahnung|widerspruch|anwalt|frist|vertrag|behörde|jobcenter|ausländerbehörde)\b/u', $t)) {
        return true;
    }

    return false;
}

/**
 * Extract a deal keyword (simple normalization)
 * You can improve later: translate to DE keywords, remove stopwords, etc.
 */
function extractDealKeyword(string $text): string {
    $t = trim($text);
    // Very simple heuristics: remove budget numbers to improve search keyword
    $t = preg_replace('/\b\d+[\.,]?\d*\b/u', '', $t);
    $t = preg_replace('/[€$]/u', '', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t) ?: 'Laptop';
}
<?php
/**
 * lak24 AI Chatbot — REST API Endpoint (for mobile app) (Improved)
 *
 * Endpoints:
 *   POST /api.php?action=chat      — Send message and/or upload file for translation
 *   GET  /api.php?action=history   — Get conversation history
 *   POST /api.php?action=clear     — Clear conversation history
 *   GET  /api.php?action=welcome   — Get welcome message & config
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

// OfferSearch (pass affiliate config too if available)
$search = new OfferSearch(
    array_merge($config['search'] ?? [], ['affiliate' => $config['affiliate'] ?? []]),
    $cache,
    $logger
);

// Security headers + CORS
$guard->setSecurityHeaders();
$guard->setCORSHeaders($config['api']['cors'] ?? []);

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// API enabled?
if (!($config['api']['enabled'] ?? true)) {
    apiResponse(503, ['error' => 'API is currently disabled']);
}

// Authenticate API key
$apiKey = readApiKeyFromHeaders();
$expectedKey = (string)($config['api']['api_key'] ?? '');

if (!validateApiKey($apiKey, $expectedKey)) {
    apiResponse(401, ['error' => 'Invalid or missing API key']);
}

// Rate limiting
$rateCheck = $guard->checkRateLimit();
if (!$rateCheck['allowed']) {
    apiResponse(429, [
        'error'    => 'Rate limit exceeded. Please try again later.',
        'reset_at' => $rateCheck['reset_at'],
    ]);
}

// Route by action
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'chat':
        handleChat($config, $sessions, $guard, $search, $logger);
        break;

    case 'history':
        handleHistory($sessions);
        break;

    case 'clear':
        handleClear($sessions);
        break;

    case 'welcome':
        handleWelcome($config);
        break;

    default:
        apiResponse(400, [
            'error'   => 'Invalid action',
            'actions' => ['chat', 'history', 'clear', 'welcome'],
        ]);
}

// ─────────────────────────────────────────────────────────────
// Handlers
// ─────────────────────────────────────────────────────────────

function handleChat(array $config, SessionManager $sessions, BotGuard $guard, OfferSearch $search, Logger $logger): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiResponse(405, ['error' => 'POST required']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) $input = $_POST;

    $hasFile     = !empty($_FILES['file']);
    $userMessage = $input['message'] ?? '';
    $userMessage = $guard->sanitizeInput((string)$userMessage);

    if (!$input && !$hasFile) {
        apiResponse(400, ['error' => 'Message or file is required']);
    }
    if (!$hasFile && empty(trim($userMessage))) {
        apiResponse(400, ['error' => 'Message is required when no file is uploaded']);
    }

    // System prompt
    $systemPrompt = @file_get_contents($config['bot']['system_prompt']);
    if ($systemPrompt === false) {
        $logger->error('System prompt file not found');
        apiResponse(500, ['error' => 'Internal server error']);
    }

    // Get session
    $sessionId = $input['session_id'] ?? null;
    $session   = $sessions->getSession($sessionId);
    $sessionId = $session['id'];

    // Injection check
    if (!$hasFile && $guard->detectInjection($userMessage)) {
        apiResponse(200, [
            'reply'      => scopeRefusal($userMessage),
            'session_id' => $sessionId,
            'type'       => 'refusal',
        ]);
    }

    // ── File path: force translate intent ──
    if ($hasFile) {
        $processor = new FileProcessor($config['upload'], $logger);

        $validation = $guard->validateUpload($_FILES['file'], $config['upload']);
        if (!$validation['valid']) {
            apiResponse(400, ['error' => $validation['error']]);
        }

        $processed = $processor->processUpload($_FILES['file']);
        if (empty($processed['success'])) {
            apiResponse(400, ['error' => $processed['error'] ?? 'File processing failed']);
        }

        $intent = 'translate';

        if (!isAllowedIntent($intent, $config['bot']['allowed_intents'] ?? [])) {
            apiResponse(200, [
                'reply'      => scopeRefusal($userMessage),
                'session_id' => $sessionId,
                'type'       => 'refusal',
            ]);
        }

        if (empty(trim($userMessage))) {
            $userMessage = 'ترجم المحتوى التالي من/إلى الألمانية حسب اللغة المكتشفة. اعرض النص الأصلي أولاً ثم الترجمة.';
        }

        [$model, $maxTokens, $temperature] = chooseModelAndLimits($intent, $userMessage, true, $config);

        $openaiCfg = $config['openai'];
        $openaiCfg['model']       = $model;
        $openaiCfg['max_tokens']  = $maxTokens;
        $openaiCfg['temperature'] = $temperature;

        $chatgpt = new ChatGPT($openaiCfg, $logger);

        // Save user message
        $sessions->addMessage($sessionId, 'user', "📄 تم رفع ملف: " . ($_FILES['file']['name'] ?? 'file') . "\n" . $userMessage);

        $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

        if (($processed['type'] ?? '') === 'image') {
            $result = $chatgpt->sendVisionMessage(
                $messages,
                (string)$processed['data'],
                (string)$processed['mime'],
                $userMessage,
                ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => 0.2]
            );
        } else {
            $text = (string)$processed['data'];
            if (mb_strlen($text) > 15000) {
                $text = mb_substr($text, 0, 15000) . "\n\n[... تم اقتطاع النص بسبب الطول]";
            }
            $messages[] = ['role' => 'user', 'content' => $userMessage . "\n\n---\n\n" . $text];
            $result = $chatgpt->sendMessage($messages, ['model' => $model, 'max_tokens' => $maxTokens, 'temperature' => 0.2]);
        }

        if (empty($result['success'])) {
            apiResponse(500, [
                'error'      => 'Translation failed. Please try again.',
                'session_id' => $sessionId,
            ]);
        }

        $reply = (string)($result['message'] ?? '');
        $sessions->addMessage($sessionId, 'assistant', $reply);

        apiResponse(200, [
            'reply'      => $reply,
            'session_id' => $sessionId,
            'type'       => $intent,
            'model'      => $model,
            'usage'      => $result['usage'] ?? [],
        ]);
    }

    // ── Text path ──
    $intent = detectIntent($userMessage);

    // Scope enforcement
    if (!isAllowedIntent($intent, $config['bot']['allowed_intents'] ?? [])) {
        apiResponse(200, [
            'reply'      => scopeRefusal($userMessage),
            'session_id' => $sessionId,
            'type'       => 'refusal',
        ]);
    }

    // Save user
    $sessions->addMessage($sessionId, 'user', $userMessage);

    // Deals enhancement (inject real links/results)
    $enhancedMessage = $userMessage;
    if ($intent === 'deals') {
        $maxPrice = extractPrice($userMessage);
        $keyword  = extractDealKeyword($userMessage);

        $searchResults = $search->search($keyword, $maxPrice);
        $searchLinks   = $search->generateSearchLinks($keyword, $maxPrice);

        $linksText  = "\n\n[SYSTEM NOTE — Use ONLY these real results/links. Do NOT invent any others.]\n";
        $linksText .= "Search keyword used: {$keyword}\n";
        if ($maxPrice !== null) $linksText .= "Max budget: {$maxPrice} EUR\n";

        if (!empty($searchResults['results'])) {
            $linksText .= "\nFound specific product matches:\n";
            $linksText .= $search->formatResultsForBot($searchResults);
        }

        $linksText .= "\nDirect search links:\n";
        foreach ($searchLinks as $link) {
            $linksText .= "• {$link['icon']} {$link['name']}: {$link['url']}\n";
        }

        $linksText .= "\nIMPORTANT INSTRUCTIONS FOR YOUR REPLY:\n";
        $linksText .= "1) Reply in the SAME language as the user.\n";
        $linksText .= "2) List up to 5 best offers with links + short explanation.\n";
        $linksText .= "3) Do NOT invent products/links.\n";

        $enhancedMessage = $userMessage . $linksText;
    }

    // Choose model + limits
    [$model, $maxTokens, $temperature] = chooseModelAndLimits($intent, $userMessage, false, $config);

    $openaiCfg = $config['openai'];
    $openaiCfg['model']       = $model;
    $openaiCfg['max_tokens']  = $maxTokens;
    $openaiCfg['temperature'] = $temperature;

    $chatgpt = new ChatGPT($openaiCfg, $logger);

    $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

    // Replace last user message with enhanced version (deals)
    if ($enhancedMessage !== $userMessage) {
        $messages[count($messages) - 1] = ['role' => 'user', 'content' => $enhancedMessage];
    }

    $result = $chatgpt->sendMessage($messages, [
        'model'       => $model,
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
    ]);

    if (empty($result['success'])) {
        apiResponse(500, [
            'error'      => 'Processing failed. Please try again.',
            'session_id' => $sessionId,
        ]);
    }

    $reply = (string)($result['message'] ?? '');
    $sessions->addMessage($sessionId, 'assistant', $reply);

    apiResponse(200, [
        'reply'      => $reply,
        'session_id' => $sessionId,
        'type'       => $intent,
        'model'      => $model,
        'usage'      => $result['usage'] ?? [],
    ]);
}

function handleHistory(SessionManager $sessions): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiResponse(405, ['error' => 'GET required']);
    }

    $sessionId = $_GET['session_id'] ?? null;
    $session   = $sessions->getSession($sessionId);

    apiResponse(200, [
        'session_id' => $session['id'],
        'messages'   => $session['messages'] ?? [],
        'updated_at' => $session['updated_at'] ?? null,
    ]);
}

function handleClear(SessionManager $sessions): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiResponse(405, ['error' => 'POST required']);
    }

    $sessionId = $_GET['session_id'] ?? null;
    // Clearing = create a new session effectively
    $session = $sessions->getSession(null);

    apiResponse(200, [
        'cleared'    => true,
        'session_id' => $session['id'],
    ]);
}

function handleWelcome(array $config): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiResponse(405, ['error' => 'GET required']);
    }

    apiResponse(200, [
        'bot_name'        => $config['bot']['name'] ?? 'lak24 bot',
        'language'        => $config['bot']['language'] ?? 'ar',
        'welcome_message' => $config['bot']['welcome_message'] ?? 'مرحباً!',
        'scope'           => $config['bot']['allowed_intents'] ?? [],
        'max_results'     => $config['search']['max_results'] ?? 5,
    ]);
}

// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

function apiResponse(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function readApiKeyFromHeaders(): string
{
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!$apiKey) $apiKey = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    $apiKey = (string)$apiKey;
    if (stripos($apiKey, 'Bearer ') === 0) {
        $apiKey = substr($apiKey, 7);
    }
    return trim($apiKey);
}

function validateApiKey(string $provided, string $expected): bool
{
    if ($expected === '') return false;
    if ($provided === '') return false;
    return hash_equals($expected, $provided);
}

function detectLanguage(string $message): string
{
    $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $message);
    $latinChars  = preg_match_all('/[a-zA-Z]/u', $message);
    return ($arabicChars > $latinChars) ? 'ar' : 'en';
}

function scopeRefusal(string $userMessage): string
{
    $lang = detectLanguage($userMessage);
    if ($lang === 'ar') {
        return "عذرًا، هذا البوت يجيب فقط على: (1) العروض مع روابط، (2) الترجمة من/إلى الألمانية، (3) كتابة رسائل/سيرة/Anschreiben/Kündigung بالألمانية، (4) المساعدة في تعبئة Anträge، (5) أسئلة الحياة في ألمانيا.";
    }
    return "Sorry — this bot only handles: (1) deals with links, (2) German translation, (3) German writing (CV/Anschreiben/Kündigung), (4) filling German forms (Anträge), (5) life in Germany questions.";
}

function detectIntent(string $text): string
{
    $t = mb_strtolower($text);

    if (preg_match('/\b(ترجم|ترجمة|übersetz|uebersetz|translate|translation)\b/u', $t)) {
        return 'translate';
    }

    if (preg_match('/\b(anschreiben|lebenslauf|cv|bewerbung|kündigung|kuendigung|email|e-mail|اكتب|ايميل|رسالة|سيرة ذاتية|write|letter)\b/u', $t)) {
        return 'writing';
    }

    if (preg_match('/\b(antrag|anträge|antraege|formular|jobcenter|wohngeld|kinderzuschlag|elterngeld|bürgergeld|ausländerbehörde|anmeldung|تعبئة|استمارة|نموذج|form|fill)\b/u', $t)) {
        return 'forms';
    }

    if (preg_match('/\b(عرض|عروض|deal|deals|offer|offers|angebot|angebote|rabatt|خصم|laptop|notebook|لابتوب|preis|unter|bis|eur|€|best)\b/u', $t)) {
        return 'deals';
    }

    if (preg_match('/\b(ألمانيا|المانيا|المانية|الالمانية|deutschland|germany|german'
        . '|berlin|برلين|hamburg|هامبورج|هامبورغ|münchen|munich|ميونخ|ميونيخ'
        . '|frankfurt|فرانكفورت|köln|كولن|كولونيا|düsseldorf|دوسلدورف|stuttgart|شتوتغارت'
        . '|hannover|هانوفر|bremen|بريمن|dresden|دريسدن|leipzig|لايبزيغ|nürnberg|نورنبرغ'
        . '|dortmund|دورتموند|essen|إيسن|bonn|بون|aachen|آخن|freiburg|فرايبورغ'
        . '|اين|أين|تقع|مدينة|ولاية|مقاطعة|منطقة'
        . '|visa|فيزا|إقامة|اقامة|سكن|تأمين|عمل|job|live|arbeiten|wohnen|studieren'
        . '|aufenthalt|niederlassung|einbürgerung|جنسية|تجنس|لجوء|asyl'
        . '|schule|مدرسة|جامعة|universität|kindergeld|kindergarten'
        . ')\b/u', $t)) {
        return 'life_de';
    }

    return 'other';
}

function isAllowedIntent(string $intent, array $allowed): bool
{
    return in_array($intent, $allowed, true);
}

function extractPrice(string $message): ?float
{
    if (preg_match('/(\d+[\.,]?\d*)\s*(?:€|يورو|euro|eur)/iu', $message, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }
    if (preg_match('/(?:أقل|اقل|لا يزيد|لا يتجاوز|بحد أقصى|unter|bis|maximal|max)\s+(?:من|عن)?\s*(\d+[\.,]?\d*)/iu', $message, $m)) {
        return (float) str_replace(',', '.', $m[1]);
    }
    return null;
}

function extractDealKeyword(string $text): string
{
    $t = trim($text);
    $t = preg_replace('/\b\d+[\.,]?\d*\b/u', '', $t);
    $t = preg_replace('/[€$]/u', '', $t);
    $t = preg_replace('/\s+/u', ' ', $t);
    return trim($t) ?: 'Laptop';
}

function chooseModelAndLimits(string $intent, string $userText, bool $hasFile, array $config): array
{
    $routing = $config['openai']['routing'] ?? [
        'light'   => 'gpt-4o-mini',
        'default' => 'gpt-5-mini',
        'heavy'   => 'gpt-5.1',
    ];

    $limits     = $config['openai']['limits'] ?? [];
    $defaultMax = (int)($config['openai']['max_tokens'] ?? 900);

    $t = mb_strtolower($userText);
    $wordCount = str_word_count($userText);

    $sensitive = preg_match('/\b(kündigung|kuendigung|abmahnung|widerspruch|klage|anwalt|frist|vertrag|behörde|jobcenter|ausländerbehörde|bürgergeld|paragraf)\b/u', $t);

    $isLight = (!$hasFile && $wordCount < 60 && in_array($intent, ['life_de'], true));
    $isHeavy = ($hasFile && $intent === 'translate') || $sensitive || ($intent === 'forms' && $wordCount > 200) || ($intent === 'writing' && $wordCount > 220);

    $model = $routing['default'] ?? 'gpt-5-mini';
    if ($isHeavy) $model = $routing['heavy'] ?? 'gpt-5.1';
    elseif ($isLight) $model = $routing['light'] ?? 'gpt-4o-mini';

    $maxTokens = (int)($limits[$intent] ?? $limits['default'] ?? $defaultMax);

    $temperature = 0.2;
    if ($intent === 'life_de' && $isLight) $temperature = 0.3;
    if ($intent === 'translate') $temperature = 0.2;

    return [$model, $maxTokens, $temperature];
}
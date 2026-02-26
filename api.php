<?php
/**
 * lak24 AI Chatbot — REST API Endpoint (for mobile app)
 * 
 * Provides API access for the mobile application.
 * Requires API key authentication via X-API-Key header.
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
$logger    = new Logger($config['logging']);
$cache     = new Cache($config['cache']);
$guard     = new BotGuard($config['rate_limit'], $logger);
$sessions  = new SessionManager($config['session']);
$chatgpt   = new ChatGPT($config['openai'], $logger);
$processor = new FileProcessor($config['upload'], $logger);
$search    = new OfferSearch($config['search'], $cache, $logger);

// Set security & CORS headers
$guard->setSecurityHeaders();
$guard->setCORSHeaders($config['api']['cors']);

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Check if API is enabled
if (!($config['api']['enabled'] ?? true)) {
    apiResponse(503, ['error' => 'API is currently disabled']);
}

// Authenticate API key (skip for OPTIONS)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (strpos($apiKey, 'Bearer ') === 0) {
    $apiKey = substr($apiKey, 7);
}

if (!$guard->validateApiKey($apiKey, $config['api']['api_key'])) {
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

// Route request
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'chat':
        handleChat($config, $chatgpt, $sessions, $guard, $search, $processor, $logger);
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

// ─── Action Handlers ─────────────────────────────────────────────────

function handleChat(array $config, ChatGPT $chatgpt, SessionManager $sessions, BotGuard $guard, OfferSearch $search, FileProcessor $processor, Logger $logger): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiResponse(405, ['error' => 'POST required']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input && !empty($_POST)) {
        $input = $_POST;
    }

    $hasFile = !empty($_FILES['file']);
    $userMessage = $input['message'] ?? '';
    
    if (!$input && !$hasFile) {
        apiResponse(400, ['error' => 'Message or file is required', 'example' => ['message' => 'أريد عروض تلفزيون', 'session_id' => 'optional-id']]);
    }
    if (!$hasFile && empty($userMessage)) {
        apiResponse(400, ['error' => 'Message is required when no file is uploaded']);
    }

    $userMessage = $guard->sanitizeInput($userMessage);
    $sessionId   = $input['session_id'] ?? null;
    $systemPrompt = file_get_contents($config['bot']['system_prompt']);
    $session      = $sessions->getSession($sessionId);
    $sessionId    = $session['id'];

    if ($hasFile) {
        $file = $_FILES['file'];
        
        $validation = $guard->validateUpload($file, $config['upload']);
        if (!$validation['valid']) {
            apiResponse(400, ['error' => $validation['error']]);
        }

        $processed = $processor->processUpload($file);
        if (!$processed['success']) {
            apiResponse(400, ['error' => $processed['error']]);
        }

        if (empty($userMessage)) {
            $userMessage = 'قم بترجمة المحتوى من الألمانية إلى العربية.';
        }

        $sessions->addMessage($sessionId, 'user', "📄 ملف: {$file['name']}\n{$userMessage}");
        $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

        $result = null;
        if ($processed['type'] === 'image') {
            $result = $chatgpt->sendVisionMessage($messages, $processed['data'], $processed['mime'], $userMessage);
        } elseif ($processed['type'] === 'text') {
            $textContent = $processed['data'];
            if (mb_strlen($textContent) > 15000) {
                $textContent = mb_substr($textContent, 0, 15000) . "\n\n[... تم اقتطاع النص]";
            }
            $messages[] = [
                'role'    => 'user', 
                'content' => "USER INSTRUCTIONS: " . $userMessage . "\n\nFILE CONTENT TO TRANSLATE:\n" . $textContent
            ];
            $result = $chatgpt->sendMessage($messages);
        }

        if (!$result || (!$result['success'])) {
            $logger->error('API translation failed', ['error' => $result['error'] ?? 'Unknown error']);
            apiResponse(503, ['error' => 'فشلت الترجمة. يرجى المحاولة مرة أخرى.']);
        }

        $cleanReply = mb_convert_encoding($result['message'], 'UTF-8', 'UTF-8');
        $sessions->addMessage($sessionId, 'assistant', $cleanReply);

        apiResponse(200, [
            'reply'      => $cleanReply,
            'session_id' => $sessionId,
            'type'       => 'translation',
            'filename'   => $file['name'],
            'usage'      => $result['usage'] ?? [],
        ]);
        return;
    }

    // Normal Text Chat Flow
    if (empty(trim($userMessage))) {
        apiResponse(400, ['error' => 'Message cannot be empty']);
    }

    // Injection check
    if ($guard->detectInjection($userMessage)) {
        apiResponse(200, [
            'reply'      => 'أعتذر، لا أستطيع معالجة هذا الطلب.',
            'session_id' => $sessionId,
            'type'       => 'error',
        ]);
    }

    // (Message will be added to session later after intent analysis)

    // Intent detection & offer search
    $intent          = $chatgpt->analyzeIntent($userMessage);
    $enhancedMessage = $userMessage;
    $userLang        = detectLanguage($userMessage);

    if ($intent === 'offer_search') {
        $maxPrice = extractPriceFromMessage($userMessage);
        $germanKeyword = $chatgpt->extractProductKeyword($userMessage);
        $searchVariants = $chatgpt->getSearchVariants($germanKeyword);
        $searchResults    = $search->search($germanKeyword, $maxPrice, '', $searchVariants);
        $formattedResults = $search->formatResultsForBot($searchResults);
        
        $header = $userLang === 'ar' ? 'ملاحظة للنظام — استخدم هذه الروابط الحقيقية فقط' : 'SYSTEM NOTE — Use ONLY these real results/links';
        $instruction = $userLang === 'ar' ? "1. يجب أن يكون ردك باللغة العربية حصراً.\n" : "1. You MUST reply in ENGLISH only.\n";

        $enhancedMessage  = $userMessage . "\n\n[" . $header . "]\n" . $formattedResults . "\n\nIMPORTANT INSTRUCTIONS:\n" . $instruction . "2. Present the specific products found clearly.\n3. Do NOT invent or hallucinate any other products or links.";
    }

    // Add user message to session (including search enhancements if any)
    $sessions->addMessage($sessionId, 'user', $enhancedMessage);

    $messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

    $result = $chatgpt->sendMessage($messages);

    if (!$result['success']) {
        $logger->error('API chat failed', ['error' => $result['error']]);
        apiResponse(500, ['error' => 'حدث خطأ أثناء المعالجة. يرجى المحاولة مرة أخرى.']);
    }

    $sessions->addMessage($sessionId, 'assistant', $result['message']);

    apiResponse(200, [
        'reply'      => $result['message'],
        'session_id' => $sessionId,
        'type'       => $intent,
        'usage'      => $result['usage'] ?? [],
    ]);
}

function handleHistory(SessionManager $sessions): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiResponse(405, ['error' => 'GET required']);
    }

    $sessionId = $_GET['session_id'] ?? null;
    if (empty($sessionId)) {
        apiResponse(400, ['error' => 'session_id is required']);
    }

    $session = $sessions->getSession($sessionId);

    // Filter out system messages and internal data
    $history = array_map(function ($msg) {
        return [
            'role'      => $msg['role'],
            'content'   => is_string($msg['content']) ? $msg['content'] : '[file content]',
            'timestamp' => $msg['timestamp'] ?? null,
        ];
    }, $session['messages']);

    apiResponse(200, [
        'session_id' => $session['id'],
        'messages'   => array_values($history),
        'count'      => count($history),
    ]);
}

function handleClear(SessionManager $sessions): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiResponse(405, ['error' => 'POST required']);
    }

    $input     = json_decode(file_get_contents('php://input'), true);
    $sessionId = $input['session_id'] ?? $_POST['session_id'] ?? null;

    if (empty($sessionId)) {
        apiResponse(400, ['error' => 'session_id is required']);
    }

    $sessions->clearHistory($sessionId);

    apiResponse(200, [
        'success'    => true,
        'session_id' => $sessionId,
        'message'    => 'تم مسح المحادثة بنجاح.',
    ]);
}

function handleWelcome(array $config): void
{
    apiResponse(200, [
        'bot_name'        => $config['bot']['name'],
        'welcome_message' => $config['bot']['welcome_message'],
        'capabilities'    => [
            'offer_search' => true,
            'translation'  => true,
            'writing'      => true,
            'file_upload'  => true,
        ],
        'allowed_files'   => $config['upload']['allowed_types'],
        'max_file_size'   => $config['upload']['max_size'],
    ]);
}

// ─── Helpers ─────────────────────────────────────────────────────────

function apiResponse(int $code, array $data): void
{
    try {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        echo $json;
    } catch (\JsonException $e) {
        // Fallback for encoding errors: sanitize again and try partial output
        error_log("JSON Encoding Error: " . $e->getMessage());
        echo json_encode(['error' => 'Encoding error in response'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function extractPriceFromMessage(string $message): ?float
{
    if (preg_match('/(\d+[\.,]?\d*)\s*(?:€|يورو|euro|eur)/iu', $message, $matches)) {
        return (float) str_replace(',', '.', $matches[1]);
    }
    if (preg_match('/(?:أقل|اقل|لا يزيد|لا يتجاوز|بحد أقصى|بحدود|unter|bis|maximal|max)\s+(?:من|عن)?\s*(\d+[\.,]?\d*)/iu', $message, $matches)) {
        return (float) str_replace(',', '.', $matches[1]);
    }
    return null;
}

/**
 * Detect if message is primarily Arabic or Latin script
 */
function detectLanguage(string $message): string
{
    $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $message);
    $latinChars  = preg_match_all('/[a-zA-Z]/u', $message);
    
    if ($arabicChars > $latinChars) {
        return 'ar';
    }
    return 'en';
}

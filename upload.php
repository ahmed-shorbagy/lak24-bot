<?php
/**
 * lak24 AI Chatbot — File Upload Endpoint
 * 
 * Handles file uploads (PDF, images) for translation.
 * 
 * POST /upload.php
 * Body: multipart/form-data with 'file' and 'session_id'
 * Optional: 'prompt' (custom translation instruction)
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

// Initialize components
$logger    = new Logger($config['logging']);
$cache     = new Cache($config['cache']);
$guard     = new BotGuard($config['rate_limit'], $logger);
$sessions  = new SessionManager($config['session']);
$chatgpt   = new ChatGPT($config['openai'], $logger);
$processor = new FileProcessor($config['upload'], $logger);

// Set security headers
$guard->setSecurityHeaders();
$guard->setCORSHeaders($config['api']['cors']);

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, ['error' => 'Method not allowed']);
}

// Rate limiting
$rateCheck = $guard->checkRateLimit();
if (!$rateCheck['allowed']) {
    jsonResponse(429, [
        'error'    => 'تم تجاوز الحد الأقصى للطلبات. يرجى المحاولة بعد قليل.',
        'reset_at' => $rateCheck['reset_at'],
    ]);
}

// Check if file was uploaded
if (empty($_FILES['file'])) {
    jsonResponse(400, ['error' => 'No file uploaded. Please send a PDF or image file.']);
}

$file      = $_FILES['file'];
$sessionId = $_POST['session_id'] ?? null;
$prompt    = $_POST['prompt'] ?? '';

// Validate the upload
$validation = $guard->validateUpload($file, $config['upload']);
if (!$validation['valid']) {
    jsonResponse(400, ['error' => $validation['error']]);
}

// Load system prompt
$systemPrompt = file_get_contents($config['bot']['system_prompt']);

// Get or create session
$session   = $sessions->getSession($sessionId);
$sessionId = $session['id'];

// Process the file
$processed = $processor->processUpload($file);

if (!$processed['success']) {
    $logger->error('File processing failed', [
        'filename' => $file['name'],
        'error'    => $processed['error'],
    ]);
    jsonResponse(400, ['error' => $processed['error']]);
}

// Prepare the translation prompt
if (empty($prompt)) {
    $prompt = 'قم بترجمة المحتوى التالي من الألمانية إلى العربية. اعرض النص الأصلي أولاً ثم الترجمة. إذا كان النص بلغة أخرى غير الألمانية، قم بترجمته أيضاً.';
}
$prompt = $guard->sanitizeInput($prompt);

// Add user message about the file upload
$sessions->addMessage($sessionId, 'user', "📄 تم رفع ملف: {$file['name']}\n{$prompt}");

// Build messages
$messages = $sessions->getMessagesForAPI($sessionId, $systemPrompt);

$result = null;

if ($processed['type'] === 'image') {
    // Use Vision API for images
    $result = $chatgpt->sendVisionMessage(
        $messages,
        $processed['data'],
        $processed['mime'],
        $prompt
    );
} elseif ($processed['type'] === 'text') {
    // PDF text extracted — send as regular message
    $textContent = $processed['data'];

    // Truncate if too long (GPT-4o-mini context limit)
    if (mb_strlen($textContent) > 15000) {
        $textContent = mb_substr($textContent, 0, 15000) . "\n\n[... تم اقتطاع النص بسبب الطول]";
    }

    $messages[] = [
        'role'    => 'user',
        'content' => $prompt . "\n\n---\n\n" . $textContent,
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

// Save assistant response to session
$sessions->addMessage($sessionId, 'assistant', $reply);

jsonResponse(200, [
    'reply'      => $reply,
    'session_id' => $sessionId,
    'type'       => 'translation',
    'filename'   => $file['name'],
    'usage'      => $result['usage'],
]);

// ─── Helper ──────────────────────────────────────────────────────────

function jsonResponse(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

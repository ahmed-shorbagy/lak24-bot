<?php
/**
 * Logger — Simple file-based logging utility (Improved)
 *
 * - Supports: log_api_calls, log_errors, redact_sensitive, log_payload
 * - Masks sensitive data (keys, auth headers, tokens, emails/phones best-effort)
 * - Avoids logging full request/response bodies when log_payload=false
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class Logger
{
    private string $logPath;
    private int $maxFileSize;
    private bool $enabled;

    private bool $logApiCalls;
    private bool $logErrors;

    private bool $redactSensitive;
    private bool $logPayload;

    public function __construct(array $config)
    {
       $storagePath = $config['storage_path'] ?? (__DIR__ . '/../logs/');

// If a relative path is provided, resolve it against DOCUMENT_ROOT
if (!is_string($storagePath) || $storagePath === '') {
    $storagePath = (__DIR__ . '/../logs/');
} else {
    // Normalize slashes
    $storagePath = str_replace('\\', '/', $storagePath);

    // Collapse accidental duplicate "/Chatbot/Chatbot/" -> "/Chatbot/"
    $storagePath = preg_replace('~/Chatbot/Chatbot/~i', '/Chatbot/', $storagePath);

    // If still relative, make it absolute under DOCUMENT_ROOT
    if ($storagePath[0] !== '/' && !preg_match('~^[A-Za-z]:/~', $storagePath)) {
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot !== '') {
            $storagePath = $docRoot . '/' . ltrim($storagePath, '/');
        }
    }
}

$this->logPath = rtrim($storagePath, "/") . '/';
        $this->maxFileSize    = (int)($config['max_file_size'] ?? 5 * 1024 * 1024);
        $this->enabled        = (bool)($config['enabled'] ?? true);

        $this->logApiCalls    = (bool)($config['log_api_calls'] ?? true);
        $this->logErrors      = (bool)($config['log_errors'] ?? true);

        $this->redactSensitive = (bool)($config['redact_sensitive'] ?? true);
        $this->logPayload      = (bool)($config['log_payload'] ?? false);

        if ($this->enabled && !is_dir($this->logPath)) {
            @mkdir($this->logPath, 0755, true);
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        if (!$this->logErrors) return;
        $this->write('ERROR', $message, $context);
    }

    /**
     * Log an API call (only if enabled)
     */
    public function apiCall(string $endpoint, int $tokens, float $duration, array $context = []): void
    {
        if (!$this->logApiCalls) return;

        $context['endpoint'] = $endpoint;
        $context['tokens']   = $tokens;
        $context['duration'] = round($duration, 3) . 's';

        $this->write('API', 'API call completed', $context);
    }

    /**
     * Low-level log writer
     */
    private function write(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) return;

        $date    = date('Y-m-d');
        $time    = date('Y-m-d H:i:s');
        $logFile = $this->logPath . "bot_{$date}.log";

        // Rotate if too large
        if (is_file($logFile) && filesize($logFile) > $this->maxFileSize) {
            $rotated = $this->logPath . "bot_{$date}_" . time() . ".log";
            @rename($logFile, $rotated);
        }

        if (!empty($context)) {
            $context = $this->sanitizeContext($context);
        }

        $contextStr = !empty($context)
            ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : '';

        $entry = "[{$time}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Sanitize context:
     * - remove/shorten payload fields when log_payload=false
     * - redact sensitive values when redact_sensitive=true
     */
    private function sanitizeContext(array $context): array
    {
        // If payload logging disabled, remove common large/sensitive fields
        if (!$this->logPayload) {
            $dropKeys = [
                'payload', 'request', 'response', 'messages', 'body',
                'raw', 'raw_response', 'raw_request', 'prompt', 'completion',
                'file_content', 'image_base64'
            ];
            foreach ($dropKeys as $k) {
                if (array_key_exists($k, $context)) {
                    $context[$k] = '[omitted]';
                }
            }
        } else {
            // even if payload enabled, avoid huge logs
            foreach ($context as $k => $v) {
                if (is_string($v) && mb_strlen($v) > 1500) {
                    $context[$k] = mb_substr($v, 0, 1500) . '…[truncated]';
                }
            }
        }

        if ($this->redactSensitive) {
            $context = $this->redactArray($context);
        }

        return $context;
    }

    /**
     * Recursively redact known sensitive keys/values
     */
    private function redactArray(array $arr): array
    {
        $sensitiveKeys = [
            'authorization', 'api_key', 'openai_api_key', 'secret', 'secret_key',
            'access_key', 'token', 'refresh_token', 'password', 'x-api-key'
        ];

        foreach ($arr as $k => $v) {
            $lk = is_string($k) ? mb_strtolower($k) : $k;

            if (is_string($lk) && in_array($lk, $sensitiveKeys, true)) {
                $arr[$k] = '[redacted]';
                continue;
            }

            if (is_array($v)) {
                $arr[$k] = $this->redactArray($v);
                continue;
            }

            if (is_string($v)) {
                $arr[$k] = $this->redactString($v);
            }
        }

        return $arr;
    }

    /**
     * Best-effort redaction in strings:
     * - OpenAI keys (sk-...)
     * - Bearer tokens
     * - AWS keys patterns (very rough)
     * - Emails/phones (basic)
     */
    private function redactString(string $s): string
    {
        // OpenAI-like keys
        $s = preg_replace('/\bsk-[A-Za-z0-9_\-]{10,}\b/', 'sk-[redacted]', $s) ?? $s;

        // Authorization: Bearer ...
        $s = preg_replace('/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/i', 'Bearer [redacted]', $s) ?? $s;

        // AWS Access Key rough pattern
        $s = preg_replace('/\b(AKI|ASI|AGP)A[0-9A-Z]{16}\b/', '[aws_access_key_redacted]', $s) ?? $s;

        // Emails (basic)
        $s = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email_redacted]', $s) ?? $s;

        // Phones (basic)
        $s = preg_replace('/\+?\d[\d\s().\-]{7,}\d/', '[phone_redacted]', $s) ?? $s;

        return $s;
    }

    /**
     * Clean old log files
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        $deleted = 0;
        $cutoff  = time() - ($daysToKeep * 86400);
        $files   = glob($this->logPath . 'bot_*.log') ?: [];

        foreach ($files as $file) {
            if (@filemtime($file) < $cutoff) {
                if (@unlink($file)) $deleted++;
            }
        }

        return $deleted;
    }
}
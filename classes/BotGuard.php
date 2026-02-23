<?php
/**
 * BotGuard — Security, rate limiting, and boundary enforcement
 * 
 * Provides input sanitization, rate limiting, prompt injection protection,
 * and intent validation to keep the bot within its allowed scope.
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class BotGuard
{
    private array $rateLimitConfig;
    private ?Logger $logger;

    // Patterns that indicate prompt injection attempts
    private const INJECTION_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior|above)\s+(instructions|prompts|rules)/i',
        '/you\s+are\s+now\s+(a|an)\s+/i',
        '/override\s+(your|the)\s+(instructions|rules|limits)/i',
        '/forget\s+(everything|all|your\s+instructions)/i',
        '/system\s*prompt/i',
        '/reveal\s+(your|the)\s+(instructions|prompt|rules)/i',
        '/act\s+as\s+(if|though)\s+you/i',
        '/pretend\s+(you\s+are|to\s+be)/i',
        '/jailbreak/i',
        '/DAN\s+mode/i',
    ];

    public function __construct(array $rateLimitConfig, ?Logger $logger = null)
    {
        $this->rateLimitConfig = $rateLimitConfig;
        $this->logger = $logger;

        $storagePath = $rateLimitConfig['storage_path'] ?? __DIR__ . '/../cache/rate_limits/';
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0755, true);
        }
    }

    /**
     * Sanitize user input text
     * Removes potentially dangerous content while preserving Arabic text
     */
    public function sanitizeInput(string $input): string
    {
        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Strip HTML tags but preserve content
        $input = strip_tags($input);

        // Decode HTML entities
        $input = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Trim excessive whitespace but preserve Arabic characters
        $input = preg_replace('/\s{3,}/', '  ', $input);

        // Limit message length (max 5000 characters)
        if (mb_strlen($input) > 5000) {
            $input = mb_substr($input, 0, 5000);
        }

        return trim($input);
    }

    /**
     * Check for prompt injection attempts
     * 
     * @return bool True if injection detected
     */
    public function detectInjection(string $input): bool
    {
        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $input)) {
                if ($this->logger) {
                    $this->logger->warning('Prompt injection detected', [
                        'input'   => mb_substr($input, 0, 200),
                        'pattern' => $pattern,
                        'ip'      => $this->getClientIP(),
                    ]);
                }
                return true;
            }
        }

        return false;
    }

    /**
     * Check rate limit for a given identifier (IP address)
     * 
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    public function checkRateLimit(?string $identifier = null): array
    {
        if (!($this->rateLimitConfig['enabled'] ?? true)) {
            return ['allowed' => true, 'remaining' => 999, 'reset_at' => 0];
        }

        $identifier = $identifier ?? $this->getClientIP();
        $maxReqs    = $this->rateLimitConfig['max_requests'] ?? 30;
        $window     = $this->rateLimitConfig['window_seconds'] ?? 60;
        $file       = rtrim($this->rateLimitConfig['storage_path'], '/\\') . '/' . md5($identifier) . '.rl';

        $data = null;
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
        }

        $now = time();

        // Reset if window expired
        if (!$data || ($now - $data['window_start']) > $window) {
            $data = [
                'window_start' => $now,
                'count'       => 0,
            ];
        }

        $data['count']++;
        file_put_contents($file, json_encode($data), LOCK_EX);

        $allowed   = $data['count'] <= $maxReqs;
        $remaining = max(0, $maxReqs - $data['count']);
        $resetAt   = $data['window_start'] + $window;

        if (!$allowed && $this->logger) {
            $this->logger->warning('Rate limit exceeded', [
                'ip'    => $identifier,
                'count' => $data['count'],
                'limit' => $maxReqs,
            ]);
        }

        return [
            'allowed'   => $allowed,
            'remaining' => $remaining,
            'reset_at'  => $resetAt,
        ];
    }

    /**
     * Validate a file upload
     * 
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateUpload(array $file, array $uploadConfig): array
    {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server size limit',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
            ];
            return ['valid' => false, 'error' => $errors[$file['error']] ?? 'Unknown upload error'];
        }

        // Check file size
        if ($file['size'] > ($uploadConfig['max_size'] ?? 10 * 1024 * 1024)) {
            return ['valid' => false, 'error' => 'File size exceeds maximum allowed (10MB)'];
        }

        // Check file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $uploadConfig['allowed_types'] ?? [])) {
            return ['valid' => false, 'error' => 'File type not allowed. Allowed: ' . implode(', ', $uploadConfig['allowed_types'])];
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $uploadConfig['allowed_mimes'] ?? [])) {
            return ['valid' => false, 'error' => 'Invalid file MIME type: ' . $mime];
        }

        return ['valid' => true, 'error' => null, 'extension' => $ext, 'mime' => $mime];
    }

    /**
     * Validate API key for mobile app access
     */
    public function validateApiKey(string $providedKey, string $expectedKey): bool
    {
        return hash_equals($expectedKey, $providedKey);
    }

    /**
     * Get client IP address
     */
    public function getClientIP(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // Take first IP if comma-separated
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Set security headers on response
     */
    public function setSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Content-Type: application/json; charset=utf-8');
    }

    /**
     * Set CORS headers for API access
     */
    public function setCORSHeaders(array $corsConfig): void
    {
        $origins = $corsConfig['allowed_origins'] ?? ['*'];
        $origin  = $_SERVER['HTTP_ORIGIN'] ?? '*';

        if (in_array('*', $origins) || in_array($origin, $origins)) {
            header('Access-Control-Allow-Origin: ' . (in_array('*', $origins) ? '*' : $origin));
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $corsConfig['allowed_methods'] ?? ['POST', 'GET', 'OPTIONS']));
        header('Access-Control-Allow-Headers: ' . implode(', ', $corsConfig['allowed_headers'] ?? ['Content-Type']));
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Clean up expired rate limit files
     */
    public function cleanupRateLimits(): int
    {
        $deleted     = 0;
        $storagePath = rtrim($this->rateLimitConfig['storage_path'], '/\\') . '/';
        $files       = glob($storagePath . '*.rl');
        $window      = $this->rateLimitConfig['window_seconds'] ?? 60;

        if ($files) {
            foreach ($files as $file) {
                if ((time() - filemtime($file)) > ($window * 2)) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}

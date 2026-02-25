<?php
/**
 * BotGuard — Security, CORS, Rate Limiting, Upload Validation, Basic Injection Detection
 *
 * Compatible with lak24 chat.php improvements.
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class BotGuard
{
    private array $rateCfg;
    private ?Logger $logger;

    public function __construct(array $rateLimitConfig = [], ?Logger $logger = null)
    {
        $this->rateCfg = $rateLimitConfig ?: [
            'enabled'        => true,
            'max_requests'   => 30,
            'window_seconds' => 60,
            'storage_path'   => __DIR__ . '/../cache/rate_limits/',
        ];
        $this->logger  = $logger;

        $path = rtrim($this->rateCfg['storage_path'] ?? (__DIR__ . '/../cache/rate_limits/'), '/\\') . '/';
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $this->rateCfg['storage_path'] = $path;
    }

    /**
     * Set basic security headers
     */
    public function setSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        // CSP for API endpoints; if you serve HTML from same domain adjust accordingly
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none';");
    }

    /**
     * Set CORS headers based on config allow-list
     */
    public function setCORSHeaders(array $corsConfig): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $corsConfig['allowed_origins'] ?? [];
        $allowedMethods = $corsConfig['allowed_methods'] ?? ['POST', 'GET', 'OPTIONS'];
        $allowedHeaders = $corsConfig['allowed_headers'] ?? ['Content-Type', 'X-API-Key', 'Authorization'];

        $allowOrigin = '';

        if (in_array('*', $allowedOrigins, true)) {
            // If you really want wildcard, allow it.
            $allowOrigin = '*';
        } elseif ($origin && in_array($origin, $allowedOrigins, true)) {
            $allowOrigin = $origin;
        }

        if ($allowOrigin !== '') {
            header('Access-Control-Allow-Origin: ' . $allowOrigin);
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Allow-Methods: ' . implode(', ', $allowedMethods));
            header('Access-Control-Allow-Headers: ' . implode(', ', $allowedHeaders));
            header('Access-Control-Max-Age: 600');
        }
    }

    /**
     * Basic input sanitization
     */
    public function sanitizeInput(string $text): string
    {
        $text = trim($text);

        // Remove null bytes and control chars (except newline/tab)
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;

        // Normalize whitespace (keep new lines)
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;

        return $text;
    }

    /**
     * Basic prompt injection / jailbreak detection
     * (best-effort; not perfect by design)
     */
    public function detectInjection(string $text): bool
    {
        $t = mb_strtolower($text);

        $patterns = [
            'ignore previous instructions',
            'disregard previous instructions',
            'system prompt',
            'developer message',
            'act as',
            'jailbreak',
            'do anything now',
            'dan ',
            'simulate',
            'bypass',
            'roleplay as',
            'أظهر التعليمات',
            'تجاهل التعليمات',
            'رسالة النظام',
            'برومبت النظام',
            'افعل أي شيء',
            'تجاوز',
            'اختراق',
        ];

        foreach ($patterns as $p) {
            if (mb_strpos($t, $p) !== false) return true;
        }

        // Very long repeated characters often used to break parsing
        if (preg_match('/(.)\1{40,}/u', $text)) return true;

        return false;
    }

    /**
     * Validate uploaded file according to upload config
     *
     * Returns: ['valid'=>bool,'error'=>string|null]
     */
    public function validateUpload(array $file, array $uploadCfg): array
    {
        if (empty($file) || !isset($file['error'])) {
            return ['valid' => false, 'error' => 'Invalid upload.'];
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => $this->uploadErrorMessage((int)$file['error'])];
        }

        $maxSize = (int)($uploadCfg['max_size'] ?? 10 * 1024 * 1024);
        if (!empty($file['size']) && (int)$file['size'] > $maxSize) {
            return ['valid' => false, 'error' => 'File is too large.'];
        }

        $allowedTypes = $uploadCfg['allowed_types'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
        $allowedMimes = $uploadCfg['allowed_mimes'] ?? [];

        $name = (string)($file['name'] ?? '');
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext === '' || !in_array($ext, $allowedTypes, true)) {
            return ['valid' => false, 'error' => 'File type not allowed.'];
        }

        // MIME check (best effort)
        $tmp = $file['tmp_name'] ?? '';
        if ($tmp && is_file($tmp)) {
            $mime = $this->detectMime($tmp);
            if ($mime && !empty($allowedMimes) && !in_array($mime, $allowedMimes, true)) {
                return ['valid' => false, 'error' => 'File MIME type not allowed.'];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Simple rate limit based on IP + user agent (file-based)
     *
     * Returns: ['allowed'=>bool,'reset_at'=>int]
     */
    public function checkRateLimit(): array
    {
        $enabled = (bool)($this->rateCfg['enabled'] ?? true);
        if (!$enabled) return ['allowed' => true, 'reset_at' => time()];

        $maxRequests = (int)($this->rateCfg['max_requests'] ?? 30);
        $window      = (int)($this->rateCfg['window_seconds'] ?? 60);
        $path        = (string)($this->rateCfg['storage_path'] ?? (__DIR__ . '/../cache/rate_limits/'));

        $fingerprint = $this->clientFingerprint();
        $key         = md5($fingerprint);
        $file        = rtrim($path, '/\\') . '/' . $key . '.json';

        $now = time();
        $data = [
            'window_start' => $now,
            'count'        => 0,
        ];

        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $old = json_decode((string)$raw, true);
            if (is_array($old) && isset($old['window_start'], $old['count'])) {
                $data = $old;
            }
        }

        $windowStart = (int)($data['window_start'] ?? $now);
        $count       = (int)($data['count'] ?? 0);

        // Reset window
        if ($now - $windowStart >= $window) {
            $windowStart = $now;
            $count = 0;
        }

        $count++;

        $data = [
            'window_start' => $windowStart,
            'count'        => $count,
        ];

        @file_put_contents($file, json_encode($data), LOCK_EX);

        $resetAt = $windowStart + $window;

        if ($count > $maxRequests) {
            if ($this->logger) {
                $this->logger->warning('Rate limit exceeded', [
                    'ip'       => $this->getClientIp(),
                    'count'    => $count,
                    'reset_at' => $resetAt,
                ]);
            }
            return ['allowed' => false, 'reset_at' => $resetAt];
        }

        return ['allowed' => true, 'reset_at' => $resetAt];
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function clientFingerprint(): string
    {
        $ip = $this->getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return $ip . '|' . $ua;
    }

    private function getClientIp(): string
    {
        // Best effort for reverse proxies
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = (string)$_SERVER[$h];
                // X_FORWARDED_FOR may contain multiple IPs
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        return '0.0.0.0';
    }

    private function detectMime(string $filePath): ?string
    {
        if (!is_file($filePath)) return null;

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                return $mime ?: null;
            }
        }
        return null;
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
    	    UPLOAD_ERR_INI_SIZE  => 'File exceeds upload size limit.',
			UPLOAD_ERR_FORM_SIZE => 'File exceeds upload size limit.',
            UPLOAD_ERR_PARTIAL => 'File upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'No file uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by extension.',
            default => 'Unknown upload error.',
        };
    }
}
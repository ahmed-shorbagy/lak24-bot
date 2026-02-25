<?php
/**
 * Cache — Simple file-based caching (Improved)
 *
 * الهدف:
 * - تقليل استدعاءات الـ API (عروض/ترجمة/افتراضي)
 * - دعم "توحيد الاستعلامات" normalize_queries لتقليل التكرار في الكاش
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class Cache
{
    private string $cachePath;
    private bool $enabled;
    private array $ttlMap;

    /**
     * إذا كان true سنقوم بتوحيد (normalize) أجزاء المفتاح النصية
     * لتقليل اختلاف المفاتيح بسبب مسافات/حروف/تنقيط.
     */
    private bool $normalizeQueries;

    public function __construct(array $config)
    {
        $this->cachePath = rtrim($config['storage_path'] ?? (__DIR__ . '/../cache/'), '/\\') . '/';
        $this->enabled   = $config['enabled'] ?? true;

        $this->normalizeQueries = (bool)($config['normalize_queries'] ?? false);

        $this->ttlMap = [
            'offers'    => (int)($config['ttl_offers']    ?? 3600),
            'translate' => (int)($config['ttl_translate']  ?? 86400),
            'default'   => (int)($config['ttl_default']    ?? 1800),
        ];

        if ($this->enabled && !is_dir($this->cachePath)) {
            @mkdir($this->cachePath, 0755, true);
        }
    }

    /**
     * Get a cached value
     *
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public function get(string $key)
    {
        if (!$this->enabled) return null;

        $file = $this->getFilePath($key);
        if (!is_file($file)) return null;

        $raw = @file_get_contents($file);
        if ($raw === false) return null;

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data['expires_at']) || !array_key_exists('value', $data)) {
            $this->delete($key);
            return null;
        }

        if (time() > (int)$data['expires_at']) {
            $this->delete($key);
            return null;
        }

        return $data['value'];
    }

    /**
     * Set a cached value
     *
     * @param string $key   Cache key
     * @param mixed  $value Value to cache
     * @param string $type  Cache type for TTL lookup (offers, translate, default)
     * @param int|null $ttl Override TTL in seconds
     */
    public function set(string $key, $value, string $type = 'default', ?int $ttl = null): void
    {
        if (!$this->enabled) return;

        $ttl  = $ttl ?? ($this->ttlMap[$type] ?? $this->ttlMap['default']);
        $file = $this->getFilePath($key);

        $data = [
            'key'        => $key,
            'value'      => $value,
            'created_at' => time(),
            'expires_at' => time() + (int)$ttl,
            'type'       => $type,
        ];

        @file_put_contents(
            $file,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    /**
     * Check if a key exists and is not expired
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * Delete a cached value
     */
    public function delete(string $key): void
    {
        $file = $this->getFilePath($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Clear all cache entries (*.cache)
     */
    public function clear(): int
    {
        $deleted = 0;
        $files   = glob($this->cachePath . '*.cache') ?: [];

        foreach ($files as $file) {
            if (@unlink($file)) $deleted++;
        }

        return $deleted;
    }

    /**
     * Clean up expired entries
     */
    public function cleanup(): int
    {
        $deleted = 0;
        $files   = glob($this->cachePath . '*.cache') ?: [];

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                if (@unlink($file)) $deleted++;
                continue;
            }

            $data = json_decode($raw, true);
            $expires = is_array($data) && isset($data['expires_at']) ? (int)$data['expires_at'] : 0;

            if ($expires <= 0 || time() > $expires) {
                if (@unlink($file)) $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Generate a cache key from parameters (static helper)
     * (مُبقي عليها للتوافق مع أي كود قديم)
     */
    public static function makeKey(string $prefix, ...$parts): string
    {
        return $prefix . '_' . md5(implode('|', array_map('strval', $parts)));
    }

    /**
     * Generate a cache key using the instance config (supports normalize_queries)
     * استخدمها بدل makeKey عندما تريد الاستفادة من normalize_queries.
     */
    public function makeKeyFor(string $prefix, ...$parts): string
    {
        $normalizedParts = [];

        foreach ($parts as $p) {
            if (is_string($p)) {
                $normalizedParts[] = $this->normalizeQueries ? $this->normalizeText($p) : $p;
            } else {
                // للأرقام/المصفوفات… نخزنها بصيغة ثابتة
                $normalizedParts[] = $this->stableStringify($p);
            }
        }

        return $prefix . '_' . md5(implode('|', $normalizedParts));
    }

    /**
     * Normalize query text for better cache hits:
     * - trim
     * - lowercase (unicode-safe)
     * - collapse spaces
     * - remove most punctuation (keep letters/numbers/€)
     */
    private function normalizeText(string $text): string
    {
        $t = trim($text);
        $t = mb_strtolower($t);

        // Replace various whitespace with single spaces
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        // Remove most punctuation/symbols (keep letters, numbers, spaces, €)
        // If you want more aggressive normalization later, adjust this.
        $t = preg_replace('/[^\p{L}\p{N}\s€]/u', '', $t) ?? $t;

        // Final collapse spaces again
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    /**
     * Produce stable string for non-string parts (arrays/objects/numbers)
     */
    private function stableStringify($value): string
    {
        if (is_null($value)) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value) || is_float($value)) return (string)$value;

        if (is_array($value)) {
            // Sort keys for stability
            $sorted = $this->ksortRecursive($value);
            return json_encode($sorted, JSON_UNESCAPED_UNICODE) ?: '';
        }

        if (is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
        }

        return (string)$value;
    }

    private function ksortRecursive(array $arr): array
    {
        foreach ($arr as $k => $v) {
            if (is_array($v)) $arr[$k] = $this->ksortRecursive($v);
        }
        ksort($arr);
        return $arr;
    }

    /**
     * Get file path for a cache key
     */
    private function getFilePath(string $key): string
    {
        return $this->cachePath . md5($key) . '.cache';
    }
}
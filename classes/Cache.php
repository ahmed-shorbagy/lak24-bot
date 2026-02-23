<?php
/**
 * Cache — Simple file-based caching
 * 
 * Caches search results, translations, and other data to reduce API calls.
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

    public function __construct(array $config)
    {
        $this->cachePath = rtrim($config['storage_path'], '/\\') . '/';
        $this->enabled   = $config['enabled'] ?? true;
        $this->ttlMap    = [
            'offers'    => $config['ttl_offers']    ?? 3600,
            'translate' => $config['ttl_translate']  ?? 86400,
            'default'   => $config['ttl_default']    ?? 1800,
        ];

        if ($this->enabled && !is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
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
        if (!$this->enabled) {
            return null;
        }

        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = json_decode(file_get_contents($file), true);

        if (!$data || !isset($data['expires_at']) || !isset($data['value'])) {
            $this->delete($key);
            return null;
        }

        // Check expiry
        if (time() > $data['expires_at']) {
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
        if (!$this->enabled) {
            return;
        }

        $ttl  = $ttl ?? ($this->ttlMap[$type] ?? $this->ttlMap['default']);
        $file = $this->getFilePath($key);

        $data = [
            'key'        => $key,
            'value'      => $value,
            'created_at' => time(),
            'expires_at' => time() + $ttl,
            'type'       => $type,
        ];

        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
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
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Clear all cache
     */
    public function clear(): int
    {
        $deleted = 0;
        $files   = glob($this->cachePath . '*.cache');

        if ($files) {
            foreach ($files as $file) {
                unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Clean up expired entries
     */
    public function cleanup(): int
    {
        $deleted = 0;
        $files   = glob($this->cachePath . '*.cache');

        if ($files) {
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if (!$data || !isset($data['expires_at']) || time() > $data['expires_at']) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Generate a cache key from search parameters
     */
    public static function makeKey(string $prefix, ...$parts): string
    {
        return $prefix . '_' . md5(implode('|', $parts));
    }

    /**
     * Get file path for a cache key
     */
    private function getFilePath(string $key): string
    {
        return $this->cachePath . md5($key) . '.cache';
    }
}

<?php
/**
 * Logger — Simple file-based logging utility
 * 
 * Handles logging of API calls, errors, and usage statistics.
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

    public function __construct(array $config)
    {
        $this->logPath     = rtrim($config['storage_path'], '/\\') . '/';
        $this->maxFileSize = $config['max_file_size'] ?? 5 * 1024 * 1024;
        $this->enabled     = $config['enabled'] ?? true;

        if ($this->enabled && !is_dir($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
    }

    /**
     * Log an informational message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log an error
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log a warning
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
    }

    /**
     * Log an API call
     */
    public function apiCall(string $endpoint, int $tokens, float $duration, array $context = []): void
    {
        $context['endpoint'] = $endpoint;
        $context['tokens']   = $tokens;
        $context['duration'] = round($duration, 3) . 's';
        $this->log('API', 'API call completed', $context);
    }

    /**
     * Write log entry to file
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $date     = date('Y-m-d');
        $time     = date('Y-m-d H:i:s');
        $logFile  = $this->logPath . "bot_{$date}.log";

        // Rotate if too large
        if (file_exists($logFile) && filesize($logFile) > $this->maxFileSize) {
            $rotated = $this->logPath . "bot_{$date}_" . time() . ".log";
            rename($logFile, $rotated);
        }

        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        $entry      = "[{$time}] [{$level}] {$message}{$contextStr}" . PHP_EOL;

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Clean old log files (older than 30 days)
     */
    public function cleanup(int $daysToKeep = 30): int
    {
        $deleted  = 0;
        $cutoff   = time() - ($daysToKeep * 86400);
        $files    = glob($this->logPath . 'bot_*.log');

        if ($files) {
            foreach ($files as $file) {
                if (filemtime($file) < $cutoff) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}

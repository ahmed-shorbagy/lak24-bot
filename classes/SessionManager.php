<?php
/**
 * SessionManager — Conversation session management
 * 
 * Manages conversation history, user sessions, and context persistence.
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class SessionManager
{
    private string $storagePath;
    private int $maxHistory;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->storagePath = rtrim($config['storage_path'], '/\\') . '/';
        $this->maxHistory  = $config['max_history'] ?? 20;
        $this->timeout     = $config['timeout'] ?? 1800;

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Get or create a session
     *
     * @param string|null $sessionId Existing session ID or null for new
     * @return array Session data
     */
    public function getSession(?string $sessionId = null): array
    {
        // Generate new ID if none provided
        if (empty($sessionId)) {
            $sessionId = $this->generateSessionId();
        }

        $file = $this->getSessionFile($sessionId);

        // Load existing session
        if (file_exists($file)) {
            $session = json_decode(file_get_contents($file), true);

            if ($session && isset($session['last_activity'])) {
                // Check if session has expired
                if ((time() - $session['last_activity']) > $this->timeout) {
                    // Session expired — create new one with same ID
                    return $this->createSession($sessionId);
                }

                // Update last activity
                $session['last_activity'] = time();
                $this->saveSession($sessionId, $session);

                return $session;
            }
        }

        // Create new session
        return $this->createSession($sessionId);
    }

    /**
     * Create a new session
     */
    private function createSession(string $sessionId): array
    {
        $session = [
            'id'             => $sessionId,
            'messages'       => [],
            'created_at'     => time(),
            'last_activity'  => time(),
            'metadata'       => [
                'language'      => 'ar',
                'message_count' => 0,
            ],
        ];

        $this->saveSession($sessionId, $session);

        return $session;
    }

    /**
     * Add a message to the session history
     *
     * @param string $sessionId   Session ID
     * @param string $role        Message role (user/assistant/system)
     * @param string|array $content Message content
     * @return array Updated session
     */
    public function addMessage(string $sessionId, string $role, $content): array
    {
        $session = $this->getSession($sessionId);

        $message = [
            'role'      => $role,
            'content'   => $content,
            'timestamp' => time(),
        ];

        $session['messages'][] = $message;
        $session['metadata']['message_count']++;
        $session['last_activity'] = time();

        // Trim history if exceeds max
        if (count($session['messages']) > $this->maxHistory) {
            // Keep system messages + last N messages
            $systemMsgs = array_filter($session['messages'], fn($m) => $m['role'] === 'system');
            $otherMsgs  = array_filter($session['messages'], fn($m) => $m['role'] !== 'system');
            $otherMsgs  = array_slice($otherMsgs, -($this->maxHistory - count($systemMsgs)));
            $session['messages'] = array_merge(array_values($systemMsgs), array_values($otherMsgs));
        }

        $this->saveSession($sessionId, $session);

        return $session;
    }

    /**
     * Get messages formatted for OpenAI API
     * 
     * @param string $sessionId   Session ID
     * @param string $systemPrompt System prompt text
     * @return array Messages array for API
     */
    public function getMessagesForAPI(string $sessionId, string $systemPrompt): array
    {
        $session  = $this->getSession($sessionId);
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($session['messages'] as $msg) {
            if ($msg['role'] === 'system') {
                continue; // Skip stored system messages, we use our own
            }
            $messages[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return $messages;
    }

    /**
     * Clear a session's message history
     */
    public function clearHistory(string $sessionId): array
    {
        $session = $this->getSession($sessionId);
        $session['messages'] = [];
        $session['metadata']['message_count'] = 0;
        $this->saveSession($sessionId, $session);

        return $session;
    }

    /**
     * Delete a session entirely
     */
    public function deleteSession(string $sessionId): bool
    {
        $file = $this->getSessionFile($sessionId);
        if (file_exists($file)) {
            return unlink($file);
        }
        return false;
    }

    /**
     * Clean up expired sessions
     */
    public function cleanup(): int
    {
        $deleted = 0;
        $files   = glob($this->storagePath . '*.session');

        if ($files) {
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if (!$data || (time() - ($data['last_activity'] ?? 0)) > $this->timeout * 2) {
                    unlink($file);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /**
     * Get session count (active sessions)
     */
    public function getActiveSessions(): int
    {
        $files = glob($this->storagePath . '*.session');
        return $files ? count($files) : 0;
    }

    /**
     * Save session to file
     */
    private function saveSession(string $sessionId, array $session): void
    {
        $file = $this->getSessionFile($sessionId);
        file_put_contents($file, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Generate a unique session ID
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get session file path
     */
    private function getSessionFile(string $sessionId): string
    {
        // Sanitize session ID to prevent directory traversal
        $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);
        return $this->storagePath . $safeId . '.session';
    }
}

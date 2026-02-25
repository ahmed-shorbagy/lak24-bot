<?php
/**
 * SessionManager — File-based conversation sessions (Improved)
 *
 * Compatible with chat.php improvements.
 * - getSession()
 * - addMessage()
 * - getMessagesForAPI()
 * - Enforces max_history to reduce token cost
 * - Stores sessions safely on disk
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
        $this->storagePath = rtrim($config['storage_path'] ?? (__DIR__ . '/../sessions/'), '/\\') . '/';
        $this->maxHistory  = (int)($config['max_history'] ?? 16);
        $this->timeout     = (int)($config['timeout'] ?? 1800);

        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Get an existing session or create a new one.
     * Returns: ['id'=>string,'messages'=>array,'created_at'=>int,'updated_at'=>int]
     */
    public function getSession(?string $sessionId = null): array
    {
        // Create new session if empty
        if (!$sessionId) {
            return $this->createSession();
        }

        // Validate session id
        if (!preg_match('/^[a-zA-Z0-9_\-]{8,64}$/', $sessionId)) {
            return $this->createSession();
        }

        $file = $this->getSessionFile($sessionId);
        if (!is_file($file)) {
            return $this->createSession();
        }

        $raw = @file_get_contents($file);
        $data = json_decode((string)$raw, true);

        if (!is_array($data) || empty($data['id']) || empty($data['messages'])) {
            return $this->createSession();
        }

        // Timeout check
        $updatedAt = (int)($data['updated_at'] ?? 0);
        if ($updatedAt > 0 && (time() - $updatedAt) > $this->timeout) {
            // Session expired -> create new
            return $this->createSession();
        }

        // Enforce max history
        $data['messages'] = $this->trimHistory((array)$data['messages']);

        return $data;
    }

    /**
     * Add message to session and persist.
     */
    public function addMessage(string $sessionId, string $role, string $content): void
    {
        $session = $this->getSession($sessionId);

        $role = in_array($role, ['user', 'assistant', 'system'], true) ? $role : 'user';

        $session['messages'][] = [
            'role'       => $role,
            'content'    => (string)$content,
            'timestamp'  => time(),
        ];

        $session['messages'] = $this->trimHistory($session['messages']);
        $session['updated_at'] = time();

        $this->saveSession($sessionId, $session);
    }

    /**
     * Build messages array for OpenAI API:
     * - Add system prompt first
     * - Append conversation history
     */
    public function getMessagesForAPI(string $sessionId, string $systemPrompt): array
    {
        $session = $this->getSession($sessionId);

        $messages = [
            [
                'role'    => 'system',
                'content' => $systemPrompt,
            ],
        ];

        foreach ($session['messages'] as $m) {
            if (!isset($m['role'], $m['content'])) continue;

            // Only allow user/assistant roles in conversation history
            $role = $m['role'];
            if (!in_array($role, ['user', 'assistant'], true)) continue;

            $messages[] = [
                'role'    => $role,
                'content' => (string)$m['content'],
            ];
        }

        return $messages;
    }

    // ─────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────

    private function createSession(): array
    {
        $id = $this->generateSessionId();

        $session = [
            'id'         => $id,
            'messages'   => [],
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->saveSession($id, $session);
        return $session;
    }

    private function saveSession(string $sessionId, array $session): void
    {
        $file = $this->getSessionFile($sessionId);
        @file_put_contents($file, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function getSessionFile(string $sessionId): string
    {
        return $this->storagePath . $sessionId . '.json';
    }

    private function generateSessionId(): string
    {
        // URL-safe session id
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }

    private function trimHistory(array $messages): array
    {
        // Keep only the last maxHistory messages
        $max = max(1, $this->maxHistory);

        if (count($messages) <= $max) return $messages;

        return array_slice($messages, -$max);
    }
}
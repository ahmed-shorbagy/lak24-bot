<?php
/**
 * ChatGPT — OpenAI Chat Completions API Wrapper (Improved for lak24)
 *
 * يدعم:
 * - Text chat
 * - Vision (images) للترجمة/استخراج النص
 * - Streaming (SSE) إن كان مطلوبًا
 * - Retry logic + Logging (بدون تسريب payloads إذا Logger مضبوط)
 *
 * ملاحظات التحسين:
 * - يحترم overrides (model/max_tokens/temperature) من أي caller مثل chat.php
 * - يقلل تسجيل نص المستخدم (preview فقط) لتقليل التسريب
 * - Retry أذكى على 429/5xx
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class ChatGPT
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private float $temperature;
    private string $apiUrl;
    private ?Logger $logger;
    private int $maxRetries = 3;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->apiKey      = (string)($config['api_key'] ?? '');
        $this->model       = (string)($config['model'] ?? 'gpt-4o-mini');
        $this->maxTokens   = (int)($config['max_tokens'] ?? 900);
        $this->temperature = (float)($config['temperature'] ?? 0.2);
        $this->apiUrl      = (string)($config['api_url'] ?? 'https://api.openai.com/v1/chat/completions');
        $this->logger      = $logger;

        if (!empty($config['max_retries'])) {
            $this->maxRetries = max(1, (int)$config['max_retries']);
        }
    }

    /**
     * Send a chat message and get a response
     *
     * @param array $messages Messages array (OpenAI format)
     * @param array $options  Override options: model, max_tokens, temperature, timeout
     * @return array ['success'=>bool,'message'=>string,'usage'=>array,'error'=>string|null,'http_code'=>int|null]
     */
    public function sendMessage(array $messages, array $options = []): array
    {
        $payload = [
          'model'       => $options['model'] ?? $this->model,
          'messages'    => $messages,
          'max_completion_tokens' => $options['max_tokens'] ?? $this->maxTokens,
          'temperature' => $options['temperature'] ?? $this->temperature,
        ];

        return $this->makeRequest($payload, $options);
    }

    /**
     * Send a message with an image for vision analysis (translation/extraction)
     *
     * @param array  $messages     Previous conversation messages
     * @param string $imageBase64  Base64-encoded image data
     * @param string $mimeType     Image MIME type
     * @param string $userPrompt   User instruction for the image
     * @param array  $options      Override options: model, max_tokens, temperature, vision_detail
     * @return array
     */
    public function sendVisionMessage(
        array $messages,
        string $imageBase64,
        string $mimeType,
        string $userPrompt = '',
        array $options = []
    ): array {
        if (empty(trim($userPrompt))) {
            $userPrompt = 'ترجم النص الموجود في هذه الصورة من/إلى الألمانية حسب اللغة المكتشفة. اعرض النص الأصلي أولاً ثم الترجمة.';
        }

        // detail: "low" أرخص، "high" أدق — نترك الافتراضي high للترجمة
        $detail = $options['vision_detail'] ?? 'high';
        if (!in_array($detail, ['low', 'high'], true)) $detail = 'high';

        // Add the image message in correct OpenAI format
        $messages[] = [
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => $userPrompt],
                [
                    'type'      => 'image_url',
                    'image_url' => [
                        'url'    => "data:{$mimeType};base64,{$imageBase64}",
                        'detail' => $detail,
                    ],
                ],
            ],
        ];

        $payload = [
          'model'       => $options['model'] ?? $this->model,
          'messages'    => $messages,
          'max_completion_tokens' => $options['max_tokens'] ?? $this->maxTokens,
          'temperature' => $options['temperature'] ?? 0.2,
        ];

        return $this->makeRequest($payload, $options);
    }

    /**
     * Send a streaming request (Server-Sent Events)
     *
     * @param array    $messages
     * @param callable $callback fn(string $chunk, bool $done)
     * @param array    $options  Override options: model, max_tokens, temperature, timeout
     * @return array Final result
     */
    public function sendStreamingMessage(array $messages, callable $callback, array $options = []): array
    {
        $payload = [
          'model'       => $options['model'] ?? $this->model,
          'messages'    => $messages,
          'max_completion_tokens' => $options['max_tokens'] ?? $this->maxTokens,
          'temperature' => $options['temperature'] ?? $this->temperature,
          'stream'      => true,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 120;

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $fullResponse = '';
        $buffer       = '';

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$fullResponse, &$buffer, $callback) {
            $buffer .= $data;

            // Process complete lines
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line   = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);
                $line   = trim($line);

                if ($line === 'data: [DONE]') {
                    $callback('', true);
                    continue;
                }

                if ($line === '' || strpos($line, 'data: ') !== 0) {
                    continue;
                }

                $json = json_decode(substr($line, 6), true);
                if ($json && isset($json['choices'][0]['delta']['content'])) {
                    $chunk = (string)$json['choices'][0]['delta']['content'];
                    $fullResponse .= $chunk;
                    $callback($chunk, false);
                }
            }

            return strlen($data);
        });

        $startTime = microtime(true);
        curl_exec($ch);
        $duration  = microtime(true) - $startTime;
        $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($this->logger) {
            $this->logger->apiCall('chat/streaming', 0, $duration, [
                'model'     => $payload['model'],
                'http_code' => $httpCode,
            ]);
        }

        if ($error || $httpCode !== 200) {
            return [
                'success'   => false,
                'message'   => '',
                'error'     => $error ?: "HTTP {$httpCode}",
                'usage'     => [],
                'http_code' => $httpCode,
            ];
        }

        return [
            'success'   => true,
            'message'   => $fullResponse,
            'error'     => null,
            'usage'     => [],
            'http_code' => $httpCode,
        ];
    }

    /**
     * Analyze user intent using GPT classification (optional helper)
     * NOTE: This is optional; your chat.php now can do keyword routing.
     *
     * @param string $userMessage
     * @return string offer_search | contract_search | translation | writing | general
     */
    public function analyzeIntent(string $userMessage): string
    {
        $messages = [
            [
                'role'    => 'system',
                'content' =>
                    "You are an intent classifier for a German e-commerce chatbot. " .
                    "Classify the user message into EXACTLY ONE of these categories. " .
                    "Reply with ONLY the category name, nothing else.\n\n" .
                    "Categories:\n" .
                    "- offer_search: User wants to find/buy/compare physical products\n" .
                    "- contract_search: User wants service contracts/subscriptions\n" .
                    "- translation: User wants translation\n" .
                    "- writing: User wants help writing documents\n" .
                    "- general: Life in Germany / legal / jobs / studying / other\n"
            ],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $result = $this->sendMessage($messages, [
            // classifier = cheap + deterministic
            'model'       => 'gpt-4o-mini',
            'max_tokens'  => 10,
            'temperature' => 0.0
        ]);

        if (!empty($result['success']) && !empty($result['message'])) {
            $intent = strtolower(trim((string)$result['message']));
            $intent = preg_replace('/[^a-z_]/', '', $intent);

            $valid = ['offer_search', 'contract_search', 'translation', 'writing', 'general'];
            if (in_array($intent, $valid, true)) {
                if ($this->logger) {
                    $this->logger->info('GPT intent classification', [
                        'user_preview' => self::preview($userMessage, 80),
                        'intent'       => $intent,
                    ]);
                }
                return $intent;
            }
        }

        if ($this->logger) {
            $this->logger->warning('GPT intent classification failed, using keyword fallback', [
                'user_preview' => self::preview($userMessage, 80),
            ]);
        }

        return $this->analyzeIntentFallback($userMessage);
    }

    private function analyzeIntentFallback(string $userMessage): string
    {
        $msg = mb_strtolower($userMessage);

        $contractKw = ['تأمين','كهرباء','غاز','انترنت','عقد','سفر','رحلات',
            'versicherung','strom','gas','internet','dsl','reise','vertrag',
            'insurance','electricity','travel','contract'];

        $offerKw = ['عروض','شراء','سعر','يورو','€','euro',
            'angebot','preis','kaufen','offer','price','buy',
            'لابتوب','هاتف','موبايل','laptop','handy','smartphone'];

        $translationKw = ['ترجم','ترجمة','translate','übersetzen','uebersetzen'];
        $writingKw = ['اكتب','كتابة','email','e-mail','write','schreiben','bewerbung','lebenslauf','anschreiben','kündigung','kuendigung'];

        $scores = [
            'contract_search' => $this->matchKeywords($msg, $contractKw),
            'offer_search'    => $this->matchKeywords($msg, $offerKw),
            'translation'     => $this->matchKeywords($msg, $translationKw),
            'writing'         => $this->matchKeywords($msg, $writingKw),
        ];

        $max = max($scores);
        if ($max === 0) return 'general';
        return (string)array_search($max, $scores, true);
    }

    private function matchKeywords(string $message, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $k) {
            if (mb_strpos($message, $k) !== false) $count++;
        }
        return $count;
    }

    /**
     * Extract product keyword in German (optional helper)
     */
    public function extractProductKeyword(string $userMessage): string
    {
        $messages = [
            [
                'role'    => 'system',
                'content' =>
                    "You are a strict product keyword extractor. Given a user message in ANY language, " .
                    "extract ONLY the bare core product/item noun and translate it to German.\n" .
                    "- If the user asks for a physical device, append German exclusion keywords with a minus sign to filter out accessories. Example: Laptop -tasche -hülle -zubehör -kabel\n" .
                    "- Use e-commerce synonyms where appropriate for German merchants (e.g., if the user asks for 'Laptop', use 'Notebook' as it is more common in German catalogs like OTTO).\n" .
                    "- Return ONLY the minimum German noun(s) and any minus-prefixed exclusions needed for e-commerce search.\n" .
                    "- No explanations.\n"
            ],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $result = $this->sendMessage($messages, [
            'max_tokens'  => 30,
            'temperature' => 0.0
        ]);

        if (!empty($result['success']) && !empty($result['message'])) {
            $keyword = trim((string)$result['message']);
            $keyword = trim($keyword, "\"'„‟”");

            if ($this->logger) {
                $this->logger->info('Extracted product keyword', [
                    'user_preview' => self::preview($userMessage, 80),
                    'keyword'      => self::preview($keyword, 80),
                ]);
            }

            return $keyword;
        }

        return $userMessage;
    }

    /**
     * Make a cURL request to OpenAI API with retry logic
     */
    private function makeRequest(array $payload, array $options = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $timeout = isset($options['timeout']) ? (int)$options['timeout'] : 120;

        // ---- Compatibility: GPT-5 / o-series require max_completion_tokens (not max_tokens)
        if (isset($payload['max_tokens']) && !isset($payload['max_completion_tokens'])) {
            $payload['max_completion_tokens'] = $payload['max_tokens'];
        }
        unset($payload['max_tokens']);

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return [
                'success'   => false,
                'message'   => '',
                'error'     => 'JSON encode failed',
                'usage'     => [],
                'http_code' => null,
            ];
        }

        $lastError = '';
        $lastHttp  = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $startTime = microtime(true);

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $encoded,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $duration = microtime(true) - $startTime;
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            $lastHttp = $httpCode;

            // cURL-level error
            if ($curlErr) {
                $lastError = "cURL error: {$curlErr}";
                if ($this->logger) {
                    $this->logger->error("OpenAI request failed (attempt {$attempt})", [
                        'error'    => $curlErr,
                        'model'    => $payload['model'] ?? $this->model,
                        'http_code'=> $httpCode,
                    ]);
                }
                if ($attempt < $this->maxRetries) {
                    $this->sleepBackoff($attempt);
                    continue;
                }

                break;
            }

            $data = json_decode((string)$response, true);

            // Non-200
            if ($httpCode !== 200) {
                $errorMsg  = $data['error']['message'] ?? ("HTTP {$httpCode}");
                $lastError = $errorMsg;

                if ($this->logger) {
                    $this->logger->error("OpenAI API error (attempt {$attempt})", [
                        'http_code' => $httpCode,
                        'error'     => self::preview($errorMsg, 300),
                        'model'     => $payload['model'] ?? $this->model,
                    ]);
                }

                // Don't retry on auth/invalid request
                if (in_array($httpCode, [400, 401, 403], true)) {
                    return [
                        'success'   => false,
                        'message'   => '',
                        'error'     => $errorMsg,
                        'usage'     => [],
                        'http_code' => $httpCode,
                    ];
                }

                // Retry on rate limit or server errors
                if ($attempt < $this->maxRetries && ($httpCode === 429 || $httpCode >= 500)) {
                    $this->sleepBackoff($attempt);
                    continue;
                }

                return [
                    'success'   => false,
                    'message'   => '',
                    'error'     => $errorMsg,
                    'usage'     => [],
                    'http_code' => $httpCode,
                ];
            }

            // Success
            $message = $data['choices'][0]['message']['content'] ?? '';
            $usage   = $data['usage'] ?? [];

            if ($this->logger) {
                $this->logger->apiCall('chat/completions', (int)($usage['total_tokens'] ?? 0), $duration, [
                    'model'             => $payload['model'] ?? $this->model,
                    'prompt_tokens'     => (int)($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
                    'http_code'         => $httpCode,
                ]);
            }

            return [
                'success'   => true,
                'message'   => (string)$message,
                'error'     => null,
                'usage'     => $usage,
                'http_code' => $httpCode,
            ];
        }

        // All retries failed
        return [
            'success'   => false,
            'message'   => '',
            'error'     => "Failed after {$this->maxRetries} attempts: {$lastError}",
            'usage'     => [],
            'http_code' => $lastHttp,
        ];
    }

    private function sleepBackoff(int $attempt): void
    {
        // backoff: 1s, 2s, 3s (بدون مبالغة)
        $sec = min(3, max(1, $attempt));
        sleep($sec);
    }

    private static function preview(string $text, int $max = 120): string
    {
        $t = trim($text);
        if (mb_strlen($t) <= $max) return $t;
        return mb_substr($t, 0, $max) . '…';
    }

    /**
     * Rough token estimate (kept)
     */
    public static function estimateTokens(string $text): int
    {
        $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $otherChars  = mb_strlen($text) - $arabicChars;
        return (int)ceil($arabicChars / 2 + $otherChars / 4);
    }
}
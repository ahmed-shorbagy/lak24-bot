<?php
/**
 * ChatGPT — OpenAI API wrapper for GPT-4o-mini
 * 
 * Handles all communication with the OpenAI Chat Completions API,
 * including text messages, vision (image analysis), and streaming responses.
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
        $this->apiKey      = $config['api_key'];
        $this->model       = $config['model'] ?? 'gpt-4o-mini';
        $this->maxTokens   = $config['max_tokens'] ?? 2048;
        $this->temperature = $config['temperature'] ?? 0.7;
        $this->apiUrl      = $config['api_url'] ?? 'https://api.openai.com/v1/chat/completions';
        $this->logger      = $logger;
    }

    /**
     * Send a chat message and get a response
     *
     * @param array $messages Messages array (OpenAI format)
     * @param array $options  Override options
     * @return array ['success' => bool, 'message' => string, 'usage' => array, 'error' => string|null]
     */
    public function sendMessage(array $messages, array $options = []): array
    {
        $payload = [
            'model'       => $options['model'] ?? $this->model,
            'messages'    => $messages,
            'max_tokens'  => $options['max_tokens'] ?? $this->maxTokens,
            'temperature' => $options['temperature'] ?? $this->temperature,
        ];

        return $this->makeRequest($payload);
    }

    /**
     * Send a message with an image for vision analysis (translation from image)
     *
     * @param array  $messages     Previous conversation messages
     * @param string $imageBase64  Base64-encoded image data
     * @param string $mimeType     Image MIME type
     * @param string $userPrompt   User's instruction for the image
     * @return array Response
     */
    public function sendVisionMessage(array $messages, string $imageBase64, string $mimeType, string $userPrompt = ''): array
    {
        if (empty($userPrompt)) {
            $userPrompt = 'Translate the text found in this image accurately.';
        }

        // Add the image message
        $messages[] = [
            'role'    => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $userPrompt,
                ],
                [
                    'type'      => 'image_url',
                    'image_url' => [
                        'url'    => "data:{$mimeType};base64,{$imageBase64}",
                        'detail' => 'high',
                    ],
                ],
            ],
        ];

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => 0.3, // Lower temperature for translation accuracy
        ];

        return $this->makeRequest($payload);
    }

    /**
     * Send a message with web search enabled (OpenAI Responses API)
     * 
     * Uses the built-in web_search tool to let GPT browse the internet
     * for real product offers when local databases return no results.
     * 
     * @param array  $messages   Messages array (OpenAI format)
     * @param string $userLang   User's language code ('ar', 'de', 'en')
     * @return array ['success' => bool, 'message' => string, 'error' => string|null]
     */
    public function sendMessageWithWebSearch(array $messages, string $userLang = 'en'): array
    {
        // The Responses API uses a different format: convert messages to input
        $input = [];
        foreach ($messages as $msg) {
            $input[] = [
                'role'    => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        $payload = [
            'model' => $this->model,
            'input' => $input,
            'tools' => [
                [
                    'type' => 'web_search_preview',
                ],
            ],
            'tool_choice'        => 'auto',
            'temperature'        => $this->temperature,
        ];

        // Use the Responses API endpoint
        $responsesUrl = 'https://api.openai.com/v1/responses';

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $lastError = '';

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $startTime = microtime(true);

            $ch = curl_init($responsesUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $duration = microtime(true) - $startTime;
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                $lastError = "cURL error: {$curlErr}";
                if ($attempt < $this->maxRetries) sleep($attempt);
                continue;
            }

            $data = json_decode($response, true);

            if ($httpCode !== 200) {
                $errorMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
                $lastError = $errorMsg;
                if ($this->logger) {
                    $this->logger->error("Web search API error (attempt {$attempt})", [
                        'http_code' => $httpCode,
                        'error'     => $errorMsg,
                    ]);
                }
                if (in_array($httpCode, [401, 403, 400])) {
                    return ['success' => false, 'message' => '', 'error' => $errorMsg, 'usage' => []];
                }
                if ($attempt < $this->maxRetries) sleep($attempt);
                continue;
            }

            // Extract text from the Responses API output
            $message = '';
            if (isset($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $outputItem) {
                    if (($outputItem['type'] ?? '') === 'message' && isset($outputItem['content'])) {
                        foreach ($outputItem['content'] as $content) {
                            if (($content['type'] ?? '') === 'output_text') {
                                $message .= $content['text'];
                            }
                        }
                    }
                }
            }

            if ($this->logger) {
                $this->logger->apiCall('responses (web_search)', $data['usage']['total_tokens'] ?? 0, $duration, [
                    'model' => $payload['model'],
                ]);
            }

            return [
                'success' => true,
                'message' => $message,
                'error'   => null,
                'usage'   => $data['usage'] ?? [],
            ];
        }

        return [
            'success' => false,
            'message' => '',
            'error'   => "Web search failed after {$this->maxRetries} attempts: {$lastError}",
            'usage'   => [],
        ];
    }

    /**
     * Send a streaming request (Server-Sent Events)
     *
     * @param array    $messages Messages array
     * @param callable $callback Function to call for each chunk: fn(string $chunk, bool $done)
     * @return array Final result with usage info
     */
    public function sendStreamingMessage(array $messages, callable $callback): array
    {
        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $this->temperature,
            'stream'      => true,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => 120,
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

                if (empty($line) || $line === 'data: [DONE]') {
                    if ($line === 'data: [DONE]') {
                        $callback($fullResponse, true);
                    }
                    continue;
                }

                if (strpos($line, 'data: ') === 0) {
                    $json = json_decode(substr($line, 6), true);
                    if ($json && isset($json['choices'][0]['delta']['content'])) {
                        $chunk = $json['choices'][0]['delta']['content'];
                        $fullResponse .= $chunk;
                        $callback($chunk, false);
                    }
                }
            }

            return strlen($data);
        });

        $startTime = microtime(true);
        curl_exec($ch);
        $duration  = microtime(true) - $startTime;
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error     = curl_error($ch);
        curl_close($ch);

        if ($this->logger) {
            $this->logger->apiCall('chat/streaming', 0, $duration, [
                'model'     => $this->model,
                'http_code' => $httpCode,
            ]);
        }

        if ($error || $httpCode !== 200) {
            return [
                'success' => false,
                'message' => '',
                'error'   => $error ?: "HTTP {$httpCode}",
                'usage'   => [],
            ];
        }

        return [
            'success' => true,
            'message' => $fullResponse,
            'error'   => null,
            'usage'   => [],
        ];
    }

    /**
     * Analyze user intent using GPT classification
     *
     * Uses a fast, low-token GPT call to classify the user's message
     * into one of the supported intents. Dramatically more accurate
     * than keyword matching for multilingual and ambiguous queries.
     *
     * @param string $userMessage The user's message
     * @return string Intent: 'offer_search', 'contract_search', 'translation', 'writing', 'general'
     */
    public function analyzeIntent(string $userMessage): string
    {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'You are an intent classifier for a German e-commerce chatbot. Classify the user message into EXACTLY ONE of these categories. Reply with ONLY the category name, nothing else.

Categories:
- offer_search: User wants to find, buy, or compare physical products (laptops, phones, clothes, TVs, shoes, etc.)
- contract_search: User wants service contracts or subscriptions (electricity, gas, internet, DSL, insurance, car rental, travel offers, mobile contracts, SIM cards)
- translation: User wants text translated between languages
- writing: User wants help writing documents (emails, CVs, cover letters, applications, formal letters)
- follow_up: Short questions, clarifications, or replies that depend on previous context (e.g., "why?", "tell me more", "explain this", "?what", "how so?", "show me more")
- general: General questions about life in Germany, legal advice, jobs, studying, or anything else

Examples:
"اريد لابتوب رخيص" → offer_search
"I need a TV under 500" → offer_search
"أريد عروض سفر" → contract_search
"I want internet contract" → contract_search
"عقد كهرباء" → contract_search
"ترجم هذا النص" → translation
"اكتب لي ايميل" → writing
"؟ماذا" → follow_up
"why?" → follow_up
"tell me more" → follow_up
"كيف احصل على تأشيرة" → general
"What is Anmeldung?" → general'
            ],
            [
                'role'    => 'user',
                'content' => $userMessage
            ]
        ];

        $result = $this->sendMessage($messages, [
            'model'       => 'gpt-4o-mini', // Use mini for classification (fast + cheap)
            'max_tokens'  => 10,
            'temperature' => 0.0
        ]);

        if ($result['success'] && !empty($result['message'])) {
            $intent = strtolower(trim($result['message']));
            $intent = preg_replace('/[^a-z_]/', '', $intent);

            $validIntents = ['offer_search', 'contract_search', 'translation', 'writing', 'follow_up', 'general'];
            
            if (in_array($intent, $validIntents)) {
                if ($this->logger) {
                    $this->logger->info('GPT intent classification', [
                        'message' => mb_substr($userMessage, 0, 100),
                        'intent'  => $intent
                    ]);
                }
                return $intent;
            }
        }

        // Fallback: basic keyword matching if GPT classification fails
        if ($this->logger) {
            $this->logger->warning('GPT intent classification failed, using keyword fallback');
        }
        return $this->analyzeIntentFallback($userMessage);
    }

    /**
     * Fallback keyword-based intent detection (used only if GPT classification fails)
     */
    private function analyzeIntentFallback(string $userMessage): string
    {
        $msg = mb_strtolower($userMessage);

        $contractKw = ['تأمين', 'كهرباء', 'غاز', 'انترنت', 'عقد', 'سفر', 'رحلات',
            'versicherung', 'strom', 'gas', 'internet', 'dsl', 'reise', 'vertrag',
            'insurance', 'electricity', 'travel', 'contract'];
        
        $offerKw = ['عروض', 'شراء', 'سعر', 'يورو', '€', 'euro',
            'angebot', 'preis', 'kaufen', 'offer', 'price', 'buy',
            'لابتوب', 'هاتف', 'موبايل', 'laptop', 'handy', 'smartphone'];

        $translationKw = ['ترجم', 'ترجمة', 'translate', 'übersetzen'];
        $writingKw = ['اكتب', 'كتابة', 'email', 'write', 'schreiben', 'bewerbung', 'lebenslauf'];
        $followupKw = ['ماذا', 'لماذا', 'كيف', 'وضح', 'فسر', 'شرح', 'المزيد', '؟', '?', 'what', 'why', 'how', 'explain', 'more', 'describe'];

        $scores = [
            'contract_search' => $this->matchKeywords($msg, $contractKw),
            'offer_search'    => $this->matchKeywords($msg, $offerKw),
            'translation'     => $this->matchKeywords($msg, $translationKw),
            'writing'         => $this->matchKeywords($msg, $writingKw),
            'follow_up'       => $this->matchKeywords($msg, $followupKw),
        ];

        $max = max($scores);
        if ($max === 0) return 'general';
        return array_search($max, $scores);
    }

    /**
     * Count keyword matches in a message
     */
    private function matchKeywords(string $message, array $keywords): int
    {
        $count = 0;
        foreach ($keywords as $keyword) {
            if (mb_strpos($message, $keyword) !== false) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Extract and translate product keyword from user message to German
     * 
     * Uses a fast, low-token GPT call to convert any language query
     * into a clean German product search keyword.
     * 
     * Example: "اريد افضل عروض الملابس تحت ال 400 يورو" → "Kleidung"
     * Example: "ابحث عن لابتوب رخيص" → "Laptop günstig"
     * 
     * @param string $userMessage The user's message in any language
     * @return string German product keyword for search URLs
     */
    public function extractProductKeyword(string $userMessage): string
    {
        $messages = [
            [
            'role'    => 'system',
            'content' => 'You are a strict product keyword extractor for a German comparison site. Given a user message in ANY language, extract ONLY the bare core product/item noun and translate it to German.
CRITICAL RULES:
- DO NOT include prices, numbers, or currencies.
- DO NOT include adjectives like "cheap", "best", "new".
- EXCLUDE accessories (cases, cables, bags, docking stations) unless they are the PRIMARY focus of the message.
- For electronics, prefer specific device names (e.g., "Laptop", "TV", "Smartphone"). Avoid the generic term "Handy" unless specifically requested for older models.
- Return ONLY the bare minimum German noun(s). No explanations.

Examples:
"I want a cheap laptop under 300 eur" → Laptop
"أريد أفضل عروض الموبايلات تحت 400" → Smartphone
"looking for a washing machine" → Waschmaschine
"تلفزيون سامسونج 55 بوصة" → Samsung Fernseher
"أريد عروض أحذية رياضية رخيصة" → Sportschuhe'
        ],
            [
                'role'    => 'user',
                'content' => $userMessage
            ]
        ];

        $result = $this->sendMessage($messages, [
            'max_tokens'  => 30,
            'temperature' => 0.0
        ]);

        if ($result['success'] && !empty($result['message'])) {
            $keyword = trim($result['message']);
            // Remove any quotes or extra punctuation
            $keyword = trim($keyword, '"\'""„‟');
            
            if ($this->logger) {
                $this->logger->info('Extracted product keyword', [
                    'original' => $userMessage,
                    'keyword'  => $keyword
                ]);
            }
            
            return $keyword;
        }

        // Fallback: return original message
        return $userMessage;
    }

    /**
     * Get brand-specific search variants for a generic product keyword.
     * 
     * Generic keywords like "Smartphone" match too many irrelevant items
     * in product databases. This method returns brand-specific alternatives
     * that produce much better search results.
     * 
     * @param string $keyword The extracted product keyword
     * @return array Array of search queries to try (includes original)
     */
    public function getSearchVariants(string $keyword): array
    {
        $keywordLower = mb_strtolower($keyword);

        // Map generic electronics categories to brand-specific search terms
        $variantMap = [
            'smartphone' => [
                'Samsung Galaxy Smartphone',
                'iPhone Apple',
                'Xiaomi Redmi Smartphone',
                'Google Pixel',
                'OnePlus Smartphone',
                'Motorola Smartphone',
            ],
            'handy' => [
                'Samsung Galaxy Smartphone',
                'iPhone Apple',
                'Xiaomi Smartphone',
            ],
            'laptop' => [
                'Lenovo Laptop Notebook',
                'HP Laptop Notebook',
                'Dell Laptop',
                'ASUS Laptop',
                'Acer Laptop Notebook',
                'Apple MacBook',
            ],
            'notebook' => [
                'Lenovo Notebook Laptop',
                'HP Notebook Laptop',
                'ASUS Notebook',
                'Acer Notebook',
            ],
            'monitor' => [
                'Samsung Monitor',
                'LG Monitor',
                'ASUS Monitor',
                'Dell Monitor',
                'AOC Monitor',
                'BenQ Monitor',
            ],
            'bildschirm' => [
                'Samsung Monitor',
                'LG Monitor',
                'Dell Monitor',
            ],
            'fernseher' => [
                'Samsung TV Fernseher',
                'LG TV OLED',
                'Sony Fernseher',
                'TCL Fernseher',
                'Hisense Fernseher',
            ],
            'tv' => [
                'Samsung TV Fernseher',
                'LG TV OLED',
                'Sony TV',
            ],
            'tablet' => [
                'Apple iPad',
                'Samsung Galaxy Tab',
                'Lenovo Tab',
            ],
            'kopfhörer' => [
                'Sony Kopfhörer',
                'Bose Headphones',
                'Samsung Galaxy Buds',
                'Apple AirPods',
            ],
        ];

        // Check if the keyword matches any variant map
        foreach ($variantMap as $generic => $variants) {
            if (mb_strpos($keywordLower, $generic) !== false) {
                return $variants;
            }
        }

        // No variants needed — the keyword is specific enough
        return [$keyword];
    }

    /**
     * Make a cURL request to OpenAI API with retry logic
     */
    private function makeRequest(array $payload): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $lastError = '';

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $startTime = microtime(true);

            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $response = curl_exec($ch);
            $duration = microtime(true) - $startTime;
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            // cURL error
            if ($curlErr) {
                $lastError = "cURL error: {$curlErr}";
                if ($this->logger) {
                    $this->logger->error("API request failed (attempt {$attempt})", ['error' => $curlErr]);
                }
                if ($attempt < $this->maxRetries) {
                    sleep($attempt); // Exponential-ish backoff
                }
                continue;
            }

            $data = json_decode($response, true);

            // API error responses
            if ($httpCode !== 200) {
                $errorMsg = $data['error']['message'] ?? "HTTP {$httpCode}";
                $lastError = $errorMsg;

                if ($this->logger) {
                    $this->logger->error("API error (attempt {$attempt})", [
                        'http_code' => $httpCode,
                        'error'     => $errorMsg,
                    ]);
                }

                // Don't retry on auth errors or invalid requests
                if (in_array($httpCode, [401, 403, 400])) {
                    return [
                        'success' => false,
                        'message' => '',
                        'error'   => $errorMsg,
                        'usage'   => [],
                    ];
                }

                if ($attempt < $this->maxRetries) {
                    sleep($attempt);
                }
                continue;
            }

            // Success
            $message = $data['choices'][0]['message']['content'] ?? '';
            $usage   = $data['usage'] ?? [];

            if ($this->logger) {
                $this->logger->apiCall('chat/completions', $usage['total_tokens'] ?? 0, $duration, [
                    'model'            => $payload['model'],
                    'prompt_tokens'    => $usage['prompt_tokens'] ?? 0,
                    'completion_tokens'=> $usage['completion_tokens'] ?? 0,
                ]);
            }

            return [
                'success' => true,
                'message' => $message,
                'error'   => null,
                'usage'   => $usage,
            ];
        }

        // All retries failed
        return [
            'success' => false,
            'message' => '',
            'error'   => "Failed after {$this->maxRetries} attempts: {$lastError}",
            'usage'   => [],
        ];
    }

    /**
     * Estimate token count for a string (rough approximation)
     */
    public static function estimateTokens(string $text): int
    {
        // Rough estimate: ~4 chars per token for English, ~2 for Arabic
        $arabicChars = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $otherChars  = mb_strlen($text) - $arabicChars;

        return (int) ceil($arabicChars / 2 + $otherChars / 4);
    }
}

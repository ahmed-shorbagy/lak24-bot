<?php
/**
 * OfferSearch — Search engine for German online shop offers
 * 
 * Searches for product offers from German online shops with priority 
 * given to lak24.de offers. Uses GPT to generate relevant search suggestions.
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class OfferSearch
{
    private array $config;
    private Cache $cache;
    private ?Logger $logger;

    public function __construct(array $searchConfig, Cache $cache, ?Logger $logger = null)
    {
        $this->config = $searchConfig;
        $this->cache  = $cache;
        $this->logger = $logger;
    }

    /**
     * Search for offers — combines lak24 and external results
     *
     * @param string   $query     Product search query
     * @param float|null $maxPrice Maximum price in EUR
     * @param string   $category  Product category (optional)
     * @param array    $searchVariants Brand-specific search variants (optional)
     * @return array Search results
     */
    public function search(string $query, ?float $maxPrice = null, string $category = '', array $searchVariants = []): array
    {
        $cacheKey = Cache::makeKey('offers', $query, (string)$maxPrice, $category);
        $cached   = $this->cache->get($cacheKey);

        if ($cached !== null) {
            if ($this->logger) {
                $this->logger->info('Offer search cache hit', ['query' => $query]);
            }
            return $cached;
        }

        $results    = [];
        $maxResults = $this->config['max_results'] ?? 5;
        $lak24Count = $this->config['lak24_priority'] ?? 3;

        // Step 1: Search lak24.de first
        $lak24Results = $this->searchLak24($query, $maxPrice, $lak24Count);
        $results      = array_merge($results, $lak24Results);

        // Step 2: Fill remaining slots with external results
        $remaining = $maxResults - count($results);
        if ($remaining > 0) {
            $externalResults = $this->searchExternal($query, $maxPrice, $category, $remaining, $searchVariants);
            $results         = array_merge($results, array_slice($externalResults, 0, $remaining));
        }

        // Step 3: Generate search links for the user
        $searchLinks = $this->generateSearchLinks($query, $maxPrice);

        $result = [
            'query'        => $query,
            'max_price'    => $maxPrice,
            'results'      => $results,
            'search_links' => $searchLinks,
            'total_found'  => count($results),
            'timestamp'    => time(),
        ];

        // Cache the results ONLY if we found products (don't cache empty results)
        if (count($results) > 0) {
            $this->cache->set($cacheKey, $result, 'offers');
        }

        if ($this->logger) {
            $this->logger->info('Offer search completed', [
                'query'   => $query,
                'results' => count($results),
            ]);
        }

        return $result;
    }

    /**
     * Search lak24.de for offers
     */
    private function searchLak24(string $query, ?float $maxPrice, int $limit): array
    {
        $baseUrl = $this->config['lak24_base_url'] ?? 'https://lak24.de';
        $results = [];

        try {
            // Try searching lak24.de's offer pages
            $searchUrl = $baseUrl . '/search?q=' . urlencode($query);

            $html = $this->fetchPage($searchUrl);
            if ($html) {
                $rawResults = $this->parseLak24Results($html, $maxPrice, $query);
                foreach ($rawResults as $r) {
                    if (count($results) >= $limit) break;
                    if ($this->isAccessory($r['title']) && !$this->isAccessoryRequested($query)) {
                        if ($this->logger) {
                            $this->logger->info('Filtered accessory from Lak24', ['title' => $r['title']]);
                        }
                        continue;
                    }
                    $results[] = $r;
                }
            }
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->warning('lak24 search failed', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Tag results as from lak24
        foreach ($results as &$result) {
            $result['source'] = 'lak24.de';
            $result['source_icon'] = '🏪';
        }

        return $results;
    }

    /**
     * Search external German price comparison sites and affiliate APIs
     */
    private function searchExternal(string $query, ?float $maxPrice, string $category, int $limit, array $searchVariants = []): array
    {
        $results = [];

        // 1. Search Amazon PA-API
        if (!empty($this->config['affiliate']['amazon']['access_key'])) {
            try {
                require_once __DIR__ . '/AmazonPAAPI.php';
                $amazon = new AmazonPAAPI($this->config['affiliate']['amazon'], $this->logger);
                $amzResults = $amazon->search($query, $maxPrice, 50); // Fetch much more to allow aggressive filtering
                foreach ($amzResults as $r) {
                    if (count($results) >= $limit) break;
                    if ($this->isAccessory($r['title']) && !$this->isAccessoryRequested($query)) continue;
                    if (!$this->isRelevantProduct($r['title'], $query)) continue;
                    $results[] = $r;
                }
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning('Amazon search failed', ['error' => $e->getMessage()]);
            }
        }

        // 2. Search local Awin SQLite database
        if (count($results) < $limit && !empty($this->config['affiliate']['awin']['data_feed_url'])) {
            try {
                require_once __DIR__ . '/AwinDatabase.php';
                $awin = new AwinDatabase($this->config['affiliate']['awin'], $this->logger);

                // Use multi-query search if we have brand variants, otherwise single query
                $useVariants = !empty($searchVariants) && count($searchVariants) > 1;
                if ($useVariants) {
                    $awinResults = $awin->searchMultiple($searchVariants, $maxPrice, 50);
                    if ($this->logger) {
                        $this->logger->info('Awin multi-query search', [
                            'variants' => count($searchVariants),
                            'raw_results' => count($awinResults)
                        ]);
                    }
                } else {
                    $awinResults = $awin->search($query, $maxPrice, 50);
                }

                $filtered = 0;
                $kept = 0;
                foreach ($awinResults as $r) {
                    if (count($results) >= $limit) break;
                    
                    // Layer 1: Negative filter — reject accessories
                    if ($this->isAccessory($r['title']) && !$this->isAccessoryRequested($query)) {
                        $filtered++;
                        if ($this->logger) {
                            $this->logger->info('Filtered accessory from Awin', ['title' => $r['title']]);
                        }
                        continue;
                    }

                    // Layer 2: Positive validation — only keep genuinely relevant products
                    if (!$this->isRelevantProduct($r['title'], $query)) {
                        $filtered++;
                        if ($this->logger) {
                            $this->logger->info('Filtered irrelevant product from Awin', ['title' => $r['title'], 'keyword' => $query]);
                        }
                        continue;
                    }

                    $kept++;
                    $results[] = $r;
                }
                if ($this->logger) {
                    $this->logger->info('Awin search filter stats', [
                        'total' => count($awinResults),
                        'kept' => $kept,
                        'filtered' => $filtered,
                        'multi_query' => $useVariants
                    ]);
                }
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning('Awin search failed', ['error' => $e->getMessage()]);
            }
        }

        // 3. Fallback to scraping idealo & geizhals if we still need more results
        if (count($results) < $limit) {
            $sources = $this->config['external_sources'] ?? [];
            foreach ($sources as $name => $baseUrl) {
                if (count($results) >= $limit) break;
                
                try {
                    switch ($name) {
                        case 'idealo':
                            $url     = $baseUrl . '/preisvergleich/MainSearchProductCategory.html?q=' . urlencode($query);
                            $fetched = $this->fetchPage($url);
                            if ($fetched) {
                                $parsed = $this->parseIdealoResults($fetched, $maxPrice, $query);
                                // Limit parsed
                                $parsed = array_slice($parsed, 0, $limit - count($results));
                                $results = array_merge($results, $parsed);
                            }
                            break;

                        case 'geizhals':
                            $url     = $baseUrl . '/?fs=' . urlencode($query);
                            $fetched = $this->fetchPage($url);
                            if ($fetched) {
                                $parsed = $this->parseGeizhalsResults($fetched, $maxPrice, $query);
                                // Limit parsed
                                $parsed = array_slice($parsed, 0, $limit - count($results));
                                $results = array_merge($results, $parsed);
                            }
                            break;
                    }
                } catch (\Exception $e) {
                    if ($this->logger) {
                        $this->logger->warning("External search failed: {$name}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $results;
    }
    /**
     * Parse lak24 search results from HTML
     */
    private function parseLak24Results(string $html, ?float $maxPrice, string $query): array
    {
        $results = [];

        // Use DOMDocument for parsing
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        // Try to find product listings - adapt selectors to lak24's actual HTML structure
        $products = $xpath->query('//div[contains(@class, "product")]|//article[contains(@class, "offer")]|//div[contains(@class, "deal")]');

        if ($products && $products->length > 0) {
            foreach ($products as $product) {
                $title = $this->extractText($xpath, './/h2|.//h3|.//a[contains(@class, "title")]', $product);
                $price = $this->extractPrice($xpath, './/*[contains(@class, "price")]', $product);
                $link  = $this->extractAttribute($xpath, './/a/@href', $product);
                $image = $this->extractAttribute($xpath, './/img/@src', $product);

                if (!empty($title) && $price !== null) {
                    // Filter out accessories if main product is likely requested
                    if ($this->isAccessory($title) && !$this->isAccessoryRequested($query)) {
                        continue;
                    }
                    if (!$this->isRelevantProduct($title, $query)) {
                        continue;
                    }

                    if ($maxPrice === null || $price <= $maxPrice) {
                        $results[] = [
                            'title' => trim($title),
                            'price' => $price,
                            'price_formatted' => number_format($price, 2, ',', '.') . ' €',
                            'link'  => $this->makeAbsoluteUrl($link, $this->config['lak24_base_url']),
                            'image' => $image,
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Parse idealo search results
     */
    private function parseIdealoResults(string $html, ?float $maxPrice, string $query): array
    {
        $results = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath    = new \DOMXPath($doc);
        $products = $xpath->query('//div[contains(@class, "offerList-item")]|//article[contains(@class, "productOffers")]');

        if ($products && $products->length > 0) {
            foreach ($products as $product) {
                $title = $this->extractText($xpath, './/*[contains(@class, "offerList-item-description")]|.//a[contains(@class, "productOffers-listItemTitleLink")]', $product);
                $price = $this->extractPrice($xpath, './/*[contains(@class, "offerList-item-price")]|.//*[contains(@class, "productOffers-listItemOfferPrice")]', $product);
                $link  = $this->extractAttribute($xpath, './/a/@href', $product);

                if (!empty($title) && $price !== null) {
                    if ($this->isAccessory($title) && !$this->isAccessoryRequested($query)) {
                        continue;
                    }
                    if (!$this->isRelevantProduct($title, $query)) {
                        continue;
                    }
                    if ($maxPrice === null || $price <= $maxPrice) {
                        $results[] = [
                            'title'           => trim($title),
                            'price'           => $price,
                            'price_formatted' => number_format($price, 2, ',', '.') . ' €',
                            'link'            => $this->makeAbsoluteUrl($link, 'https://www.idealo.de'),
                            'image'           => null,
                            'source'          => 'idealo.de',
                            'source_icon'     => '🔍',
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Parse geizhals search results
     */
    private function parseGeizhalsResults(string $html, ?float $maxPrice, string $query): array
    {
        $results = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath    = new \DOMXPath($doc);
        
        // Verified selectors from browser: .galleryview__name-link and .galleryview__price-link span
        // Also keep legacy selectors as fallback
        $products = $xpath->query('//div[contains(@class, "galleryview__item")]|//div[contains(@class, "listview__item")]|//article[contains(@class, "product")]');

        if ($products && $products->length > 0) {
            foreach ($products as $product) {
                $title = $this->extractText($xpath, './/a[contains(@class, "galleryview__name-link")]|.//a[contains(@class, "listview__name")]|.//span[contains(@class, "product__name")]', $product);
                $price = $this->extractPrice($xpath, './/*[contains(@class, "galleryview__price-link")]|.//*[contains(@class, "listview__price")]|.//*[contains(@class, "product__price")]', $product);
                $link  = $this->extractAttribute($xpath, './/a[contains(@class, "galleryview__name-link")]|.//a[contains(@class, "listview__name")]|.//a/@href', $product);

                if (!empty($title) && $price !== null) {
                    if ($this->isAccessory($title) && !$this->isAccessoryRequested($query)) {
                        continue;
                    }
                    if (!$this->isRelevantProduct($title, $query)) {
                        continue;
                    }
                    if ($maxPrice === null || $price <= $maxPrice) {
                        $results[] = [
                            'title'           => trim($title),
                            'price'           => $price,
                            'price_formatted' => number_format($price, 2, ',', '.') . ' €',
                            'link'            => $this->makeAbsoluteUrl($link, 'https://geizhals.de'),
                            'image'           => null,
                            'source'          => 'geizhals.de',
                            'source_icon'     => '🔍',
                        ];
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Generate direct search links for the user (with affiliate tags)
     * 
     * IMPORTANT: $query should already be translated to German keywords
     * before calling this method.
     */
    public function generateSearchLinks(string $query, ?float $maxPrice = null): array
    {
        $links = [];
        $encodedQuery = urlencode($query);
        $amazonTag = $this->config['affiliate']['amazon']['store_id'] ?? 'lak2400-21';

        // lak24 search
        $links[] = [
            'name' => 'lak24.de',
            'url'  => ($this->config['lak24_base_url'] ?? 'https://lak24.de') . '/search?q=' . $encodedQuery,
            'icon' => '🏪',
        ];

        // Amazon DE with affiliate tag
        $amazonUrl = "https://www.amazon.de/s?k={$encodedQuery}&tag={$amazonTag}";
        if ($maxPrice !== null) {
            // Amazon uses price range filter in cents
            $maxCents = (int)($maxPrice * 100);
            $amazonUrl .= "&rh=p_36%3A-{$maxCents}";
        }
        $links[] = [
            'name' => 'Amazon.de',
            'url'  => $amazonUrl,
            'icon' => '📦',
        ];

        // idealo
        $idealoUrl = "https://www.idealo.de/preisvergleich/MainSearchProductCategory.html?q={$encodedQuery}";
        if ($maxPrice !== null) {
            $idealoUrl .= "&maxPrice=" . (int) $maxPrice;
        }
        $links[] = [
            'name' => 'idealo.de',
            'url'  => $idealoUrl,
            'icon' => '🔍',
        ];

        // eBay DE
        $ebayUrl = "https://www.ebay.de/sch/i.html?_nkw={$encodedQuery}";
        if ($maxPrice !== null) {
            $ebayUrl .= "&_udhi=" . (int)$maxPrice;
        }
        $links[] = [
            'name' => 'eBay.de',
            'url'  => $ebayUrl,
            'icon' => '🛒',
        ];

        // geizhals (with price filter)
        $geizhalsUrl = "https://geizhals.de/?fs={$encodedQuery}";
        if ($maxPrice !== null) {
            $geizhalsUrl .= "&bpmax=" . (int)$maxPrice;
        }
        $links[] = [
            'name' => 'geizhals.de',
            'url'  => $geizhalsUrl,
            'icon' => '🔍',
        ];

        return $links;
    }

    /**
     * Format search results for the bot's response
     * 
     * IMPORTANT: This output goes to the LLM. It must be in English/neutral emojis 
     * so it doesn't bias the LLM into answering in a specific language (e.g. Arabic).
     */
    public function formatResultsForBot(array $searchData): string
    {
        $results = $searchData['results'] ?? [];
        $links   = $searchData['search_links'] ?? [];

        if (empty($results) && empty($links)) {
            return 'No products found.';
        }

        $output = '';

        if (!empty($results)) {
            $output .= "🛒 Top Offers:\n\n";

            foreach ($results as $i => $result) {
                $num     = $i + 1;
                $source  = $result['source_icon'] ?? '🔗';
                $output .= "{$num}. {$source} **{$result['title']}**\n";
                $output .= "   💰 Price: {$result['price_formatted']}\n";
                $output .= "   🏬 Store: {$result['source']}\n";
                if (!empty($result['link'])) {
                    $output .= "   🔗 Link: {$result['link']}\n";
                }
                $output .= "\n";
            }
        }

        if (!empty($links)) {
            $output .= "\n🔎 Category Links:\n";
            foreach ($links as $link) {
                $output .= "• {$link['icon']} [{$link['name']}]({$link['url']})\n";
            }
        }

        return $output;
    }

    // ─── Helper Methods ──────────────────────────────────────────────

    /**
     * Fetch a webpage via cURL
     */
    private function fetchPage(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml',
                'Accept-Language: de-DE,de;q=0.9,ar;q=0.8',
            ],
        ]);

        $html     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode !== 200) {
            return null;
        }

        return $html;
    }

    /**
     * Extract text from XPath query
     */
    private function extractText(\DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->textContent);
        }
        return '';
    }

    /**
     * Extract price from XPath query
     */
    private function extractPrice(\DOMXPath $xpath, string $query, \DOMNode $context): ?float
    {
        $text = $this->extractText($xpath, $query, $context);
        if (empty($text)) {
            return null;
        }

        // Parse European price format (1.234,56 €)
        $text = preg_replace('/[^\d,.\-]/', '', $text);
        $text = str_replace('.', '', $text); // Remove thousand separators
        $text = str_replace(',', '.', $text); // Convert decimal separator

        $price = (float) $text;
        return $price > 0 ? $price : null;
    }

    /**
     * Extract attribute from XPath query
     */
    private function extractAttribute(\DOMXPath $xpath, string $query, \DOMNode $context): string
    {
        $nodes = $xpath->query($query, $context);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }
        return '';
    }

    /**
     * Make relative URL absolute
     */
    private function makeAbsoluteUrl(string $url, string $baseUrl): string
    {
        if (empty($url)) {
            return '';
        }
        if (strpos($url, 'http') === 0) {
            return $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    /**
     * Check if a product title describes an accessory or non-product item
     */
    private function isAccessory(string $title): bool
    {
        $accessories = [
            // Cases, covers, bags
            'hülle', 'tasche', 'case', 'sleeve', 'cover', 'schutzhülle', 'display-schutz',
            'glas-schutz', 'notebooktasche', 'laptop-tasche', 'notebook-tasche',
            'umhängetasche', 'rucksack', 'backbag', 'beutel', 'tüte', 'mappe',
            // Cables, adapters, chargers
            'kabel', 'cable', 'adapter', 'netzteil', 'ladegerät', 'charger', 'powerbank',
            'displayport', 'hdmi', 'docking',
            // Mounts, stands, holders
            'halterung', 'halter', 'ständer', 'stand', 'wandhalterung', 'arm', 'stativ',
            'clip', 'ring',
            // Screen protectors
            'folie', 'schutz', 'panzerglas', 'schutzfolie',
            // Peripherals
            'mauspad', 'mousepad', 'tastatur', 'keyboard', 'maus', 'mouse',
            'fernbedienung', 'remote', 'kopfhörer', 'earbuds', 'headset',
            // Power
            'akku', 'battery', 'station',
            // Furniture & storage (catches Smartphone-Schrank, etc.)
            'schrank', 'regal', 'organizer', 'aufbewahrung', 'tisch', 'wagen',
            'ringlicht',
            // Office/stationery noise
            'textmarker', 'stift', 'marker', 'büro', 'folder', 'karton', 'box',
            'umreifungs-set', 'strapping',
            // Cleaning
            'reinigungs', 'cleaning', 'pflege', 'care',
            // Generic accessory words
            'zubehör', 'ersatzteil', 'socke', 'kamin', 'ersatz', 'deckel', 'blende', 'reinigung', 'pflegemittel',
        ];

        $titleLower = mb_strtolower($title);
        foreach ($accessories as $accessory) {
            if (mb_strpos($titleLower, $accessory) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a product title is genuinely relevant to the searched keyword.
     * 
     * This performs POSITIVE validation — the title must contain indicators
     * that it's actually the type of product being searched for, not just
     * something that happens to contain the keyword.
     * 
     * @param string $title  Product title
     * @param string $keyword The search keyword used
     * @return bool True if the product appears to be genuinely relevant
     */
    private function isRelevantProduct(string $title, string $keyword): bool
    {
        $titleLower = mb_strtolower($title);
        $keywordLower = mb_strtolower($keyword);

        // Define positive indicators for each product category
        $relevanceRules = [
            'smartphone' => [
                'brands' => ['samsung', 'apple', 'iphone', 'xiaomi', 'redmi', 'poco', 'google pixel',
                    'oneplus', 'motorola', 'oppo', 'vivo', 'realme', 'nokia', 'huawei', 'honor',
                    'nothing phone', 'fairphone', 'sony xperia'],
                'specs' => ['5g', 'lte', 'dual sim', 'android', 'ios',
                    '64gb', '128gb', '256gb', '512gb', '1tb',
                    'amoled', 'mp kamera', 'megapixel'],
                'exclusions' => ['tablet', 'ipad', 'schrank', 'ringlicht', 'stativ', 'laptop', 'notebook', 'pc', 'monitor', 'fernseher', 'tv'],
            ],
            'handy' => 'smartphone', // alias
            'laptop' => [
                'brands' => ['lenovo', 'hp ', 'dell', 'asus', 'acer', 'msi', 'apple', 'macbook',
                    'thinkpad', 'ideapad', 'inspiron', 'pavilion', 'surface', 'chromebook',
                    'tuxedo', 'framework', 'razer', 'gigabyte'],
                'specs' => ['intel', 'amd', 'ryzen', 'core i', 'ram', 'ssd', 'ghz',
                    'zoll display', 'fhd', 'full hd', 'windows', 'linux'],
                'exclusions' => ['tasche', 'hülle', 'ständer', 'smartphone', 'handy', 'iphone'],
            ],
            'notebook' => 'laptop', // alias
            'monitor' => [
                'brands' => ['samsung', 'lg', 'asus', 'dell', 'aoc', 'benq', 'philips',
                    'viewsonic', 'msi', 'iiyama', 'eizo', 'acer', 'xiaomi', 'hp '],
                'specs' => ['hz', 'zoll', 'ips', 'oled', 'va', 'tn',
                    '4k', 'qhd', 'uhd', 'wqhd', 'fhd', 'full hd',
                    '1080p', '1440p', '2160p', 'curved', 'gaming monitor',
                    'bildschirm', 'display'],
                'exclusions' => ['laptop', 'notebook', 'macbook', 'hülle', 'stativ', 'arm', 'wandhalterung', 'smartphone', 'handy', 'iphone', 'tv ', 'fernseher'],
            ],
            'bildschirm' => 'monitor', // alias
            'fernseher' => [
                'brands' => ['samsung', 'lg', 'sony', 'panasonic', 'tcl', 'hisense',
                    'philips', 'sharp', 'toshiba', 'grundig', 'xiaomi'],
                'specs' => ['oled', 'qled', 'led', 'smart tv', 'uhd', '4k', '8k',
                    'zoll', 'ambilight', 'dolby', 'hdr', 'hdmi'],
                'exclusions' => ['wandhalterung', 'stativ', 'kabel', 'laptop', 'smartphone'],
            ],
            'tv' => 'fernseher', // alias
            'tablet' => [
                'brands' => ['apple', 'ipad', 'samsung', 'galaxy tab', 'lenovo tab',
                    'huawei', 'xiaomi pad', 'microsoft surface'],
                'specs' => ['wifi', 'lte', '5g', 'zoll', 'android', 'ipados',
                    '64gb', '128gb', '256gb'],
                'exclusions' => ['hülle', 'tasche', 'panzerglas', 'laptop', 'smartphone'],
            ],
            'kühlschrank' => [
                'brands' => ['bosch', 'samsung', 'lg', 'siemens', 'liebherr', 'haier', 'gorenje', 'miele', 'koenic', 'amica', 'hisense', 'aeg', 'bomann', 'klarstein', 'priveleg', 'severin', 'beko', 'comfee'],
                'specs' => ['liter', 'kühlen', 'gefrieren', 'kombi', 'nofrost', 'side-by-side', 'kühl', 'freistehend', 'einbau'],
                'exclusions' => ['kamin', 'ofen', 'herd', 'mikrowelle', 'tasche', 'hülle', 'laptop', 'smartphone', 'fernseher'],
            ],
            'bürostuhl' => [
                'brands' => ['hjh office', 'songmics', 'topstar', 'nowy styl', 'steelcase', 'herman miller', 'interstuhl'],
                'specs' => ['ergonomisch', 'rollen', 'lehne', 'armlehnen', 'höhenverstellbar', 'wippfunktion', 'stoff', 'leder', 'netz'],
                'exclusions' => ['kühlschrank', 'laptop', 'smartphone', 'hülle', 'tasche'],
            ],
        ];

        // Find which category this keyword belongs to
        $rules = null;
        foreach ($relevanceRules as $category => $ruleSet) {
            if (mb_strpos($keywordLower, $category) !== false) {
                // Resolve aliases
                if (is_string($ruleSet)) {
                    $rules = $relevanceRules[$ruleSet];
                } else {
                    $rules = $ruleSet;
                }
                break;
            }
        }

        // If no specific rules exist for this keyword, assume relevant
        if ($rules === null) {
            return true;
        }

        // Check for exclusions first (NEGATIVE category signals)
        if (isset($rules['exclusions'])) {
            foreach ($rules['exclusions'] as $exclusion) {
                if (mb_strpos($titleLower, $exclusion) !== false) {
                    return false;
                }
            }
        }

        // Check brands (POSITIVE indicator)
        foreach ($rules['brands'] as $brand) {
            if (mb_strpos($titleLower, $brand) !== false) {
                return true;
            }
        }

        // Check specs (POSITIVE indicator)
        foreach ($rules['specs'] as $spec) {
            if (mb_strpos($titleLower, $spec) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user query explicitly requested an accessory
     */
    private function isAccessoryRequested(string $query): bool
    {
        $accessoryKeywords = [
            'hülle', 'tasche', 'case', 'sleeve', 'cover', 'docking', 'kabel', 'cable',
            'maus', 'mouse', 'tastatur', 'keyboard', 'netzteil', 'adapter', 'folie',
            'schutz', 'panzerglas', 'halterung', 'ständer', 'stand'
        ];

        $queryLower = mb_strtolower($query);
        foreach ($accessoryKeywords as $kw) {
            if (mb_strpos($queryLower, $kw) !== false) {
                return true;
            }
        }
        return false;
    }
}

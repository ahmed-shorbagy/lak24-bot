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
     * @return array Search results
     */
    public function search(string $query, ?float $maxPrice = null, string $category = ''): array
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
        $lak24Results = $this->searchLak24($query, $maxPrice, $category);
        $results      = array_merge($results, array_slice($lak24Results, 0, $lak24Count));

        // Step 2: Fill remaining slots with external results
        $remaining = $maxResults - count($results);
        if ($remaining > 0) {
            $externalResults = $this->searchExternal($query, $maxPrice, $category, $remaining);
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

        // Cache the results
        $this->cache->set($cacheKey, $result, 'offers');

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
    private function searchLak24(string $query, ?float $maxPrice, string $category): array
    {
        $baseUrl = $this->config['lak24_base_url'] ?? 'https://lak24.de';
        $results = [];

        try {
            // Try searching lak24.de's offer pages
            $searchUrl = $baseUrl . '/search?q=' . urlencode($query);

            $html = $this->fetchPage($searchUrl);
            if ($html) {
                $results = $this->parseLak24Results($html, $maxPrice);
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
    private function searchExternal(string $query, ?float $maxPrice, string $category, int $limit): array
    {
        $results = [];

        // 1. Search Amazon PA-API
        if (!empty($this->config['affiliate']['amazon']['access_key'])) {
            try {
                require_once __DIR__ . '/AmazonPAAPI.php';
                $amazon = new AmazonPAAPI($this->config['affiliate']['amazon'], $this->logger);
                $amzResults = $amazon->search($query, $maxPrice, $limit);
                $results = array_merge($results, $amzResults);
            } catch (\Exception $e) {
                if ($this->logger) $this->logger->warning('Amazon search failed', ['error' => $e->getMessage()]);
            }
        }

        // 2. Search local Awin SQLite database
        if (count($results) < $limit && !empty($this->config['affiliate']['awin']['data_feed_url'])) {
            try {
                require_once __DIR__ . '/AwinDatabase.php';
                $awin = new AwinDatabase($this->config['affiliate']['awin'], $this->logger);
                $awinResults = $awin->search($query, $maxPrice, $limit - count($results));
                $results = array_merge($results, $awinResults);
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
                                $parsed = $this->parseIdealoResults($fetched, $maxPrice);
                                // Limit parsed
                                $parsed = array_slice($parsed, 0, $limit - count($results));
                                $results = array_merge($results, $parsed);
                            }
                            break;

                        case 'geizhals':
                            $url     = $baseUrl . '/?fs=' . urlencode($query);
                            $fetched = $this->fetchPage($url);
                            if ($fetched) {
                                $parsed = $this->parseGeizhalsResults($fetched, $maxPrice);
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
    private function parseLak24Results(string $html, ?float $maxPrice): array
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
    private function parseIdealoResults(string $html, ?float $maxPrice): array
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
    private function parseGeizhalsResults(string $html, ?float $maxPrice): array
    {
        $results = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath    = new \DOMXPath($doc);
        $products = $xpath->query('//div[contains(@class, "listview__item")]|//article[contains(@class, "product")]');

        if ($products && $products->length > 0) {
            foreach ($products as $product) {
                $title = $this->extractText($xpath, './/a[contains(@class, "listview__name")]|.//span[contains(@class, "product__name")]', $product);
                $price = $this->extractPrice($xpath, './/*[contains(@class, "listview__price")]|.//*[contains(@class, "product__price")]', $product);
                $link  = $this->extractAttribute($xpath, './/a/@href', $product);

                if (!empty($title) && $price !== null) {
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
}

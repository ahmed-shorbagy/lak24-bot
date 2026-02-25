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
     * @param string     $query     Product search query
     * @param float|null $maxPrice  Maximum price in EUR
     * @param string     $category  Product category (optional)
     * @return array Search results
     */
    public function search(string $query, ?float $maxPrice = null, string $category = ''): array
    {
        // Cache key (uses normalization when available)
        if (method_exists($this->cache, 'makeKeyFor')) {
            $cacheKey = $this->cache->makeKeyFor('offers', $query, $maxPrice, $category, ($this->config['max_results'] ?? 5));
        } else {
            $cacheKey = Cache::makeKey('offers', $query, (string)$maxPrice, $category);
        }

        $cached = $this->cache->get($cacheKey);

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

        // Optional: filter by max price (safety net)
        if ($maxPrice !== null) {
            $results = $this->filterByMaxPrice($results, $maxPrice);
        }

        // Optional: de-duplicate results (same product/URL from multiple sources)
        if (!empty($this->config['dedupe_results'])) {
            $results = $this->dedupeResults($results);
        }

        // Ensure we respect max_results after filtering/deduping
        $results = array_slice($results, 0, $maxResults);

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
                                $parsed = array_slice($parsed, 0, $limit - count($results));
                                $results = array_merge($results, $parsed);
                            }
                            break;

                        case 'geizhals':
                            $url     = $baseUrl . '/?fs=' . urlencode($query);
                            $fetched = $this->fetchPage($url);
                            if ($fetched) {
                                $parsed = $this->parseGeizhalsResults($fetched, $maxPrice);
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
                $title = $this->extractText($xpath, './/h2|.//h3|.//a[contains(@class,"title")]', $product);
                $price = $this->extractText($xpath, './/*[contains(@class,"price")]', $product);
                $link  = $this->extractAttr($xpath, './/a', 'href', $product);

                if (!$title || !$link) continue;

                $absoluteLink = $this->makeAbsoluteUrl($link, $this->config['lak24_base_url'] ?? 'https://lak24.de');

                // Price filter (best effort)
                $numericPrice = $this->extractNumericPrice($price);
                if ($maxPrice !== null && $numericPrice !== null && $numericPrice > $maxPrice) {
                    continue;
                }

                $results[] = [
                    'title'       => $title,
                    'price'       => $price,
                    'url'         => $absoluteLink,
                    'description' => '',
                ];
            }
        }

        return $results;
    }

    /**
     * Parse idealo results (best effort)
     */
    private function parseIdealoResults(string $html, ?float $maxPrice): array
    {
        $results = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $items = $xpath->query('//div[contains(@class,"sr-resultList__item")] | //div[contains(@class,"resultList__item")]');
        if ($items && $items->length > 0) {
            foreach ($items as $item) {
                $title = $this->extractText($xpath, './/a[contains(@class,"sr-productSummary__title")] | .//a[contains(@class,"productSummary__title")]', $item);
                $price = $this->extractText($xpath, './/*[contains(@class,"sr-detailedPriceInfo__price")] | .//*[contains(@class,"detailedPriceInfo__price")]', $item);
                $link  = $this->extractAttr($xpath, './/a', 'href', $item);

                if (!$title || !$link) continue;

                $numericPrice = $this->extractNumericPrice($price);
                if ($maxPrice !== null && $numericPrice !== null && $numericPrice > $maxPrice) continue;

                $results[] = [
                    'title'       => $title,
                    'price'       => $price ?: '',
                    'url'         => $this->makeAbsoluteUrl($link, 'https://www.idealo.de'),
                    'source'      => 'idealo.de',
                    'source_icon' => '🔎',
                ];
            }
        }

        return $results;
    }

    /**
     * Parse geizhals results (best effort)
     */
    private function parseGeizhalsResults(string $html, ?float $maxPrice): array
    {
        $results = [];

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $items = $xpath->query('//div[contains(@class,"gh-search-result")] | //li[contains(@class,"productlist__entry")]');
        if ($items && $items->length > 0) {
            foreach ($items as $item) {
                $title = $this->extractText($xpath, './/a[contains(@class,"productlist__link")] | .//a[contains(@class,"gh-product__title")]', $item);
                $price = $this->extractText($xpath, './/*[contains(@class,"gh-price")] | .//*[contains(@class,"productlist__price")]', $item);
                $link  = $this->extractAttr($xpath, './/a', 'href', $item);

                if (!$title || !$link) continue;

                $numericPrice = $this->extractNumericPrice($price);
                if ($maxPrice !== null && $numericPrice !== null && $numericPrice > $maxPrice) continue;

                $results[] = [
                    'title'       => $title,
                    'price'       => $price ?: '',
                    'url'         => $this->makeAbsoluteUrl($link, 'https://geizhals.de'),
                    'source'      => 'geizhals.de',
                    'source_icon' => '🧾',
                ];
            }
        }

        return $results;
    }

    /**
     * Generate external search links for the user
     */
    public function generateSearchLinks(string $query, ?float $maxPrice = null): array
    {
        $links = [];

        // lak24 search
        $lak24Base = $this->config['lak24_base_url'] ?? 'https://lak24.de';
        $links[] = [
            'name' => 'lak24',
            'icon' => '🏪',
            'url'  => $lak24Base . '/search?q=' . urlencode($query),
        ];

        // external sources
        $sources = $this->config['external_sources'] ?? [];
        foreach ($sources as $name => $baseUrl) {
            switch ($name) {
                case 'idealo':
                    $links[] = [
                        'name' => 'Idealo',
                        'icon' => '🔎',
                        'url'  => $baseUrl . '/preisvergleich/MainSearchProductCategory.html?q=' . urlencode($query),
                    ];
                    break;
                case 'geizhals':
                    $links[] = [
                        'name' => 'Geizhals',
                        'icon' => '🧾',
                        'url'  => $baseUrl . '/?fs=' . urlencode($query),
                    ];
                    break;
            }
        }

        // Optional: add maxPrice hint via query text (best effort)
        if ($maxPrice !== null) {
            foreach ($links as &$l) {
                $l['url'] .= '&priceMax=' . urlencode((string)$maxPrice);
            }
        }

        return $links;
    }

    /**
     * Format results for bot (so it can list 5 offers with links)
     */
    public function formatResultsForBot(array $searchResult): string
    {
        $out = '';

        $results = $searchResult['results'] ?? [];
        if (!$results) {
            return "No concrete results found.\n";
        }

        $i = 1;
        foreach ($results as $r) {
            $title = $r['title'] ?? ($r['product_name'] ?? 'Unknown');
            $priceFormatted = $r['price_formatted'] ?? null;
            if (!$priceFormatted) {
                $rawPrice = $r['price'] ?? ($r['display_price'] ?? ($r['search_price'] ?? ''));
                if (is_numeric($rawPrice)) {
                    $priceFormatted = number_format((float)$rawPrice, 2, ',', '.') . ' €';
                } else {
                    $priceFormatted = (string)$rawPrice;
                }
            }
            $url = $r['url'] ?? ($r['link'] ?? ($r['merchant_deep_link'] ?? ($r['aw_deep_link'] ?? '')));

            $source = $r['source'] ?? ($r['merchant_name'] ?? '');
            if (!$source) $source = 'Partner Shop';
            $sourceIcon = $r['source_icon'] ?? '🏷️';

            $out .= "{$i}) {$title}\n";
            if ($priceFormatted) $out .= "   💰 Price: {$priceFormatted}\n";
            if ($source) $out .= "   🏪 Merchant: {$source}\n";
            if ($url) $out .= "   🔗 Direct Link: {$url}\n";
            $out .= "\n";
            $i++;
        }

        return $out;
    }

    /**
     * Fetch a page with simple curl
     */
    private function fetchPage(string $url): ?string
    {
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'lak24-bot/1.0 (+https://lak24.de)',
        ]);

        $html = curl_exec($ch);
        curl_close($ch);

        if (!is_string($html) || $html === '') {
            return null;
        }

        return $html;
    }

    private function extractText(\DOMXPath $xpath, string $query, \DOMNode $contextNode): string
    {
        $nodes = $xpath->query($query, $contextNode);
        if ($nodes && $nodes->length > 0) {
            return trim($nodes->item(0)->nodeValue);
        }
        return '';
    }

    private function extractAttr(\DOMXPath $xpath, string $query, string $attr, \DOMNode $contextNode): string
    {
        $nodes = $xpath->query($query, $contextNode);
        if ($nodes && $nodes->length > 0) {
            $node = $nodes->item(0);
            if ($node instanceof \DOMElement && $node->hasAttribute($attr)) {
                return trim($node->getAttribute($attr));
            }
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
     * Filter results that have a numeric price higher than maxPrice.
     * Keeps items with missing/unknown price (so the bot can still show them if needed).
     */
    private function filterByMaxPrice(array $results, float $maxPrice): array
    {
        $out = [];
        foreach ($results as $r) {
            $price = $this->extractNumericPrice($r['price'] ?? ($r['search_price'] ?? ($r['store_price'] ?? null)));
            if ($price === null || $price <= $maxPrice) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * De-duplicate by best available unique identifier (deep link/url/id/title+merchant).
     */
    private function dedupeResults(array $results): array
    {
        $seen = [];
        $out  = [];

        foreach ($results as $r) {
            $keyPart = null;

            foreach (['merchant_deep_link','aw_deep_link','url','link','product_url'] as $k) {
                if (!empty($r[$k]) && is_string($r[$k])) {
                    $keyPart = $this->normalizeKeyPart($r[$k]);
                    break;
                }
            }

            if ($keyPart === null) {
                $title = $r['title'] ?? ($r['product_name'] ?? '');
                $merchant = $r['merchant_name'] ?? ($r['source'] ?? '');
                $keyPart = $this->normalizeKeyPart($title . '|' . $merchant);
            }

            $key = md5($keyPart);
            if (isset($seen[$key])) continue;

            $seen[$key] = true;
            $out[] = $r;
        }

        return $out;
    }

    private function normalizeKeyPart(string $s): string
    {
        $s = trim(mb_strtolower($s));
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return $s;
    }

    private function extractNumericPrice($value): ?float
    {
        if ($value === null) return null;
        if (is_int($value) || is_float($value)) return (float)$value;

        $s = (string)$value;
        // Common formats: "499,00 €", "499.00 EUR", "ab 499 €"
        if (preg_match('/(\d+[\.,]?\d*)/u', $s, $m)) {
            return (float) str_replace(',', '.', $m[1]);
        }
        return null;
    }
}
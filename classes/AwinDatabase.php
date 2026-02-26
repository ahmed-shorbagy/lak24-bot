<?php
/**
 * AwinDatabase - Manages Awin affiliate product feeds using SQLite FTS5
 * 
 * Downloads the gzipped CSV data feed from Awin and inserts it into a local
 * SQLite database for ultra-fast full-text searching (FTS5) without external DBs.
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class AwinDatabase
{
    private PDO $db;
    private array $config;
    private ?Logger $logger;
    private string $dbPath;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->dbPath = __DIR__ . '/../data/awin_products.db';
        
        $this->initDb();
    }

    /**
     * Initialize SQLite database and FTS5 virtual table
     */
    private function initDb(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // FTS5 creates a virtual table optimized for full text searches
        $this->db->exec("
            CREATE VIRTUAL TABLE IF NOT EXISTS awin_products USING fts5(
                title,
                price UNINDEXED,
                link UNINDEXED,
                image UNINDEXED,
                source UNINDEXED
            );
        ");
    }

    /**
     * Download and import the Awin data feed into the SQLite database.
     * This should ideally run via a cron job CLI script.
     * 
     * @return int Number of products imported
     */
    public function importFeed(): int
    {
        $url = $this->config['data_feed_url'] ?? '';
        if (empty($url)) {
            if ($this->logger) $this->logger->error('Awin data feed URL is empty');
            return 0;
        }

        // Open gzip stream over HTTP
        $handle = gzopen($url, 'r');
        if (!$handle) {
            if ($this->logger) $this->logger->error('Failed to open Awin feed URL stream');
            return 0;
        }

        $this->db->beginTransaction();
        try {
            // Clear existing data
            $this->db->exec("DELETE FROM awin_products");

            $stmt = $this->db->prepare("INSERT INTO awin_products (title, price, link, image, source) VALUES (?, ?, ?, ?, ?)");
            
            // Skip CSV header
            $header = fgetcsv($handle); 
            $count = 0;
            
            // Read CSV line by line
            while (($data = fgetcsv($handle)) !== false) {
                // Must have at least 20 columns based on Awin URL params
                if (count($data) < 20) {
                    continue;
                }

                $link   = trim($data[0]);
                $title  = trim($data[1]);
                $image  = !empty($data[4]) ? trim($data[4]) : trim($data[12]);
                $price  = (float)str_replace(',', '.', $data[7]); // Search price usually in param 7
                $source = trim($data[8]);

                if (empty($title) || empty($link) || $price <= 0) {
                    continue;
                }

                $stmt->execute([$title, $price, $link, $image, $source]);
                $count++;

                // Commit in batches to keep memory usage low and speed high
                if ($count % 10000 === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                }
            }

            $this->db->commit();
            gzclose($handle);

            // Optimize FTS index
            $this->db->exec("INSERT INTO awin_products(awin_products) VALUES('optimize')");

            if ($this->logger) {
                $this->logger->info("Imported $count products from Awin feed");
            }
            
            return $count;

        } catch (\Exception $e) {
            $this->db->rollBack();
            gzclose($handle);
            if ($this->logger) {
                $this->logger->error('Awin import failed', ['error' => $e->getMessage()]);
            }
            return 0;
        }
    }

    /**
     * Search the local Awin product database
     *
     * @param string $query Search keywords
     * @param float|null $maxPrice Maximum price
     * @param int $limit Max results
     * @return array Array of formatted product results
     */
    public function search(string $query, ?float $maxPrice = null, int $limit = 5): array
    {
        // Prepare FTS query string e.g. "iphone"* AND "15"*
        $words = array_filter(explode(' ', $query), function($w) { 
            return mb_strlen($w) > 2; // Skip tiny words
        });

        if (empty($words)) {
            return [];
        }

        // Format terms for FTS5 boolean matching
        $matchTerms = array_map(function($w) { 
            return '"' . str_replace('"', '""', $w) . '"*'; 
        }, $words);
        $match = implode(' AND ', $matchTerms);

        try {
            $sql = "SELECT title, price, link, image, source FROM awin_products WHERE awin_products MATCH :match";
            
            if ($maxPrice !== null) {
                // In FTS5, you can filter on UNINDEXED columns after the MATCH
                $sql .= " AND price <= :maxprice";
            }
            
            $sql .= " ORDER BY rank LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':match', $match, PDO::PARAM_STR);
            if ($maxPrice !== null) {
                $stmt->bindValue(':maxprice', $maxPrice, PDO::PARAM_STR); 
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = [
                    'title'           => $row['title'],
                    'price'           => (float)$row['price'],
                    'price_formatted' => number_format((float)$row['price'], 2, ',', '.') . ' €',
                    'link'            => $row['link'],
                    'image'           => $row['image'],
                    'source'          => $row['source'] ? $row['source'] : 'متجر شريك', // Just show merchant name, hide 'Awin' backend details
                    'source_icon'     => '🏷️'
                ];
            }
            
            return $results;
            
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Awin search failed', ['error' => $e->getMessage()]);
            }
            return [];
        }
    }

    /**
     * Search with multiple query variants and merge+deduplicate results.
     * 
     * This is the key to relevance: instead of searching for "Smartphone"
     * (which returns cabinets and holders), we search for "Samsung Galaxy",
     * "iPhone", "Xiaomi Redmi", etc. and merge the results.
     * 
     * @param array $queries Array of search query strings
     * @param float|null $maxPrice Maximum price
     * @param int $limit Total results to return
     * @return array Merged and deduplicated results
     */
    public function searchMultiple(array $queries, ?float $maxPrice = null, int $limit = 10): array
    {
        $allResults = [];
        $seenTitles = [];
        $perQueryLimit = max(5, intval(ceil($limit / count($queries))));

        foreach ($queries as $query) {
            $results = $this->search($query, $maxPrice, $perQueryLimit);
            foreach ($results as $r) {
                // Deduplicate by normalized title
                $normalizedTitle = mb_strtolower(trim($r['title']));
                if (isset($seenTitles[$normalizedTitle])) {
                    continue;
                }
                $seenTitles[$normalizedTitle] = true;
                $allResults[] = $r;
            }
        }

        // Sort by price ascending (best deals first) and limit
        usort($allResults, function($a, $b) {
            return $a['price'] <=> $b['price'];
        });

        return array_slice($allResults, 0, $limit);
    }
}

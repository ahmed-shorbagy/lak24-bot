<?php
/**
 * AwinDatabase - Manages Awin affiliate product feeds using SQLite FTS5 (Improved)
 *
 * Improvements:
 * - Use gzgetcsv for gzopen stream
 * - Use bm25() for ranking (instead of ORDER BY rank)
 * - Better query normalization
 * - Sort results by price ASC then relevance
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

    private function initDb(): void
    {
        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->db = new PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // FTS5 virtual table
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
     * Download & import feed (gzipped CSV).
     * Ideally called via CLI cron job.
     */
    public function importFeed(): int
    {
        $url = $this->config['data_feed_url'] ?? '';
        if (!$url) {
            if ($this->logger) $this->logger->error('Awin data feed URL is empty');
            return 0;
        }

        $handle = @gzopen($url, 'r');
        if (!$handle) {
            if ($this->logger) $this->logger->error('Failed to open Awin feed URL stream');
            return 0;
        }

        $count = 0;

        $this->db->beginTransaction();
        try {
            $this->db->exec("DELETE FROM awin_products");

            $stmt = $this->db->prepare("
                INSERT INTO awin_products (title, price, link, image, source)
                VALUES (?, ?, ?, ?, ?)
            ");

            // Skip header line safely
            $headerLine = @gzgets($handle);
            unset($headerLine);

            while (($line = @gzgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;

                $row = str_getcsv($line, ',');
                if (!is_array($row) || count($row) < 20) continue;

                $link   = trim((string)$row[0]);
                $title  = trim((string)$row[1]);
                $image  = !empty($row[4]) ? trim((string)$row[4]) : (isset($row[12]) ? trim((string)$row[12]) : '');
                $price  = (float)str_replace(',', '.', (string)$row[7]); // search_price
                $source = trim((string)$row[8]);

                if ($title === '' || $link === '' || $price <= 0) continue;

                $stmt->execute([$title, $price, $link, $image, $source]);
                $count++;

                if ($count % 10000 === 0) {
                    $this->db->commit();
                    $this->db->beginTransaction();
                }
            }

            $this->db->commit();
            @gzclose($handle);

            // Optimize FTS index
            $this->db->exec("INSERT INTO awin_products(awin_products) VALUES('optimize')");

            if ($this->logger) $this->logger->info("Imported {$count} products from Awin feed");

            return $count;

        } catch (\Exception $e) {
            $this->db->rollBack();
            @gzclose($handle);
            if ($this->logger) $this->logger->error('Awin import failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Search local Awin DB
     */
    public function search(string $query, ?float $maxPrice = null, int $limit = 5): array
    {
        if ($limit <= 0) $limit = 5;

        $query = $this->normalizeQuery($query);

        $words = array_values(array_filter(explode(' ', $query), function ($w) {
            return mb_strlen(ltrim($w, '-')) > 2;
        }));

        if (!$words) return [];

        // Build FTS match: ("iphone"* AND "15"*) NOT "tasche"* NOT "hülle"*
        $pos = [];
        $neg = [];
        foreach ($words as $w) {
            if (str_starts_with($w, '-')) {
                $clean = ltrim($w, '-');
                $neg[] = 'NOT "' . str_replace('"', '""', $clean) . '"*';
            } else {
                $pos[] = '"' . str_replace('"', '""', $w) . '"*';
            }
        }

        if (!$pos) return [];

        $match = '(' . implode(' AND ', $pos) . ') ' . implode(' ', $neg);

        try {
            // bm25() gives a relevance score (lower is better)
            $sql = "
                SELECT title, price, link, image, source, bm25(awin_products) AS score
                FROM awin_products
                WHERE awin_products MATCH :match
            ";

            if ($maxPrice !== null) {
                $sql .= " AND CAST(price AS REAL) <= :maxprice";
            }

            // Sort: best match first, then cheapest if relevance ties
            $sql .= " ORDER BY score ASC, CAST(price AS REAL) ASC LIMIT :limit";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':match', $match, PDO::PARAM_STR);

            if ($maxPrice !== null) {
                $stmt->bindValue(':maxprice', $maxPrice, PDO::PARAM_STR);
            }

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $price = (float)$row['price'];

                $results[] = [
                    'title'           => $row['title'],
                    'price'           => $price,
                    'price_formatted' => number_format($price, 2, ',', '.') . ' €',
                    'link'            => $row['link'],
                    'image'           => $row['image'],
                    'source'          => $row['source'] ?: 'متجر شريك',
                    'source_icon'     => '🏷️',
                ];
            }

            return $results;

        } catch (\Exception $e) {
            if ($this->logger) $this->logger->error('Awin search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    protected function normalizeQuery(string $q): string
    {
        $q = trim(mb_strtolower($q));
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        // remove most punctuation, keep letters/numbers/spaces/€/- (minus allowed for FTS NOT)
        $q = preg_replace('/[^\p{L}\p{N}\s€-]/u', ' ', $q) ?? $q;
        $q = preg_replace('/\s+/u', ' ', $q) ?? $q;
        return trim($q);
    }
}
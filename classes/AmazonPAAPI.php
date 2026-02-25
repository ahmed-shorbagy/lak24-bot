<?php
/**
 * AmazonPAAPI - Client for Amazon Product Advertising API 5.0 (Improved)
 *
 * Improvements:
 * - Better result filtering + sorting by price
 * - Safer logging (no big response dumps)
 * - More resilient price parsing
 */

if (!defined('LAK24_BOT')) {
    http_response_code(403);
    exit('Access denied');
}

class AmazonPAAPI
{
    private string $accessKey;
    private string $secretKey;
    private string $partnerTag;
    private string $region;
    private string $host;
    private ?Logger $logger;

    public function __construct(array $config, ?Logger $logger = null)
    {
        $this->accessKey  = (string)($config['access_key'] ?? '');
        $this->secretKey  = (string)($config['secret_key'] ?? '');
        $this->partnerTag = (string)($config['store_id'] ?? '');
        $this->region     = (string)($config['region'] ?? 'eu-west-1');
        $this->host       = (string)($config['host'] ?? 'webservices.amazon.de');
        $this->logger     = $logger;
    }

    public function search(string $keywords, ?float $maxPrice = null, int $limit = 5): array
    {
        if ($limit <= 0) $limit = 5;

        // Ask for more items than needed so we can filter/sort
        $requestCount = max(10, min(30, $limit * 5));

        $payloadArr = [
            'Keywords'    => $keywords,
            'Resources'   => [
                'Images.Primary.Large',
                'ItemInfo.Title',
                'Offers.Listings.Price'
            ],
            'ItemCount'   => $requestCount,
            'PartnerTag'  => $this->partnerTag,
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.amazon.de',
        ];

        $payload = json_encode($payloadArr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) return [];

        $headers = $this->signRequest('POST', '/paapi5/searchitems', $payload);

        $url = "https://{$this->host}/paapi5/searchitems";
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $t0       = microtime(true);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        $dt = microtime(true) - $t0;

        if ($err || $httpCode !== 200 || !is_string($response)) {
            if ($this->logger) {
                $this->logger->error('Amazon PA-API request failed', [
                    'http_code' => $httpCode,
                    'error'     => $err ?: null,
                    'duration'  => round($dt, 3) . 's',
                    'response'  => is_string($response) ? substr($response, 0, 500) : null,
                ]);
            }
            return [];
        }

        $data = json_decode($response, true);
        if (!is_array($data)) return [];

        $results = $this->parseResults($data, $maxPrice);

        // Sort by price ascending (best deals)
        usort($results, function ($a, $b) {
            return ($a['price'] ?? PHP_FLOAT_MAX) <=> ($b['price'] ?? PHP_FLOAT_MAX);
        });

        // Return only limit
        return array_slice($results, 0, $limit);
    }

    private function parseResults(array $data, ?float $maxPrice): array
    {
        $out   = [];
        $items = $data['SearchResult']['Items'] ?? [];
        if (!is_array($items)) return [];

        foreach ($items as $item) {
            $title = $item['ItemInfo']['Title']['DisplayValue'] ?? '';
            $url   = $item['DetailPageURL'] ?? '';
            $img   = $item['Images']['Primary']['Large']['URL'] ?? '';

            $amount = $item['Offers']['Listings'][0]['Price']['Amount'] ?? null;
            $disp   = $item['Offers']['Listings'][0]['Price']['DisplayAmount'] ?? null;

            $price = null;
            if ($amount !== null && is_numeric($amount)) {
                $price = (float)$amount;
            } elseif (is_string($disp)) {
                $price = $this->extractNumericPrice($disp);
            }

            if (!$title || !$url || $price === null || $price <= 0) {
                continue;
            }

            if ($maxPrice !== null && $price > $maxPrice) {
                continue;
            }

            $out[] = [
                'title'           => $title,
                'price'           => $price,
                'price_formatted' => number_format($price, 2, ',', '.') . ' €',
                'link'            => $url,
                'image'           => $img,
                'source'          => 'Amazon.de',
                'source_icon'     => '📦',
            ];
        }

        return $out;
    }

    private function extractNumericPrice(string $s): ?float
    {
        // "499,99 €" or "499.99 EUR"
        if (preg_match('/(\d+[\.,]?\d*)/u', $s, $m)) {
            return (float)str_replace(',', '.', $m[1]);
        }
        return null;
    }

    /**
     * Generate AWS Signature V4 headers (as in your original implementation)
     */
    private function signRequest(string $method, string $uri, string $payload): array
    {
        $service   = 'ProductAdvertisingAPI';
        $timestamp = gmdate('Ymd\THis\Z');
        $date      = gmdate('Ymd');
        $amzTarget = 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems';

        $headers = [
            'content-encoding' => 'amz-1.0',
            'content-type'     => 'application/json; charset=utf-8',
            'host'             => $this->host,
            'x-amz-date'       => $timestamp,
            'x-amz-target'     => $amzTarget
        ];

        ksort($headers);

        $canonicalHeaders = '';
        $signedHeaders    = '';

        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders    .= strtolower($k) . ';';
        }

        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest =
            "$method\n$uri\n\n$canonicalHeaders\n$signedHeaders\n" . hash('sha256', $payload);

        $credentialScope =
            "$date/{$this->region}/$service/aws4_request";

        $stringToSign =
            "AWS4-HMAC-SHA256\n$timestamp\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        $kSecret  = 'AWS4' . $this->secretKey;
        $kDate    = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader =
            "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

        return [
            "Content-Encoding: amz-1.0",
            "Content-Type: application/json; charset=utf-8",
            "Host: {$this->host}",
            "X-Amz-Date: $timestamp",
            "X-Amz-Target: $amzTarget",
            "Authorization: $authHeader"
        ];
    }
}
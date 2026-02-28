<?php
/**
 * AmazonPAAPI - Client for Amazon Product Advertising API 5.0
 * 
 * Implements AWS Signature V4 for secure requests without external SDKs.
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
        $this->accessKey  = $config['access_key'];
        $this->secretKey  = $config['secret_key'];
        $this->partnerTag = $config['store_id'];
        $this->region     = $config['region'] ?? 'eu-west-1';
        $this->host       = $config['host'] ?? 'webservices.amazon.de';
        $this->logger     = $logger;
    }

    /**
     * Search Amazon for products
     *
     * @param string $keywords Product search query
     * @param float|null $maxPrice Maximum price
     * @param int $limit Max results
     * @return array Array of formatted product results
     */
    public function search(string $keywords, ?float $maxPrice = null, int $limit = 5): array
    {
        $payload = json_encode([
            'Keywords'    => $keywords,
            'Resources'   => [
                'Images.Primary.Large',
                'ItemInfo.Title',
                'OffersV2.Listings.Price'
            ],
            'ItemCount'   => 10, // Request 10, then filter top $limit below max price
            'PartnerTag'  => $this->partnerTag,
            'PartnerType' => 'Associates',
            'Marketplace' => 'www.amazon.de',
        ]);

        $headers = $this->signRequest('POST', '/paapi5/searchitems', $payload);

        $ch = curl_init("https://{$this->host}/paapi5/searchitems");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
 
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);
 
        if ($error || ($httpCode !== 200 && $httpCode !== 0)) {
             if ($this->logger) {
                $this->logger->error('Amazon PA-API request failed', [
                    'http_code' => $httpCode,
                    'error'     => $error,
                    'response'  => substr($response, 0, 500)
                ]);
            }
            return [];
        }

        $data = json_decode($response, true);
        if (!$data) {
            return [];
        }

        return $this->parseResults($data, $maxPrice, $limit);
    }

    /**
     * Parse and format the raw Amazon API response
     */
    private function parseResults(array $data, ?float $maxPrice, int $limit): array
    {
        $results = [];
        $items   = $data['SearchResult']['Items'] ?? [];

        foreach ($items as $item) {
            if (count($results) >= $limit) {
                break;
            }

            $title    = $item['ItemInfo']['Title']['DisplayValue'] ?? '';
            $url      = $item['DetailPageURL'] ?? '';
            $imageUrl = $item['Images']['Primary']['Large']['URL'] ?? '';
            $priceStr = $item['OffersV2']['Listings'][0]['Price']['Money']['Amount'] ?? null;

            if (empty($title) || $priceStr === null) {
                continue;
            }

            $price = (float)$priceStr;
            if ($maxPrice !== null && $price > $maxPrice) {
                continue; // Skip items above budget
            }

            $results[] = [
                'title'           => $title,
                'price'           => $price,
                'price_formatted' => number_format($price, 2, ',', '.') . ' €',
                'link'            => $url,
                'image'           => $imageUrl,
                'source'          => 'Amazon.de',
                'source_icon'     => '📦'
            ];
        }

        return $results;
    }

    /**
     * Generate AWS Signature V4 headers
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

        // Headers must be sorted by key
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaders    = '';

        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeaders    .= strtolower($k) . ';';
        }
        $signedHeaders = rtrim($signedHeaders, ';');

        $canonicalRequest = "$method\n$uri\n\n$canonicalHeaders\n$signedHeaders\n" . hash('sha256', $payload);
        $credentialScope  = "$date/{$this->region}/$service/aws4_request";
        $stringToSign     = "AWS4-HMAC-SHA256\n$timestamp\n$credentialScope\n" . hash('sha256', $canonicalRequest);

        // Derive signing key
        $kSecret  = 'AWS4' . $this->secretKey;
        $kDate    = hash_hmac('sha256', $date, $kSecret, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        
        // Final signature
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

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

<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
define('LAK24_BOT', true);
$config = require 'config.php';
require_once 'classes/AmazonPAAPI.php';

// Mock Logger if needed, or just pass null
$api = new AmazonPAAPI($config['affiliate']['amazon']);

echo "Searching for 'laptop'...\n";
// Re-instantiate to ensure no cached state if any
$results = $api->search('laptop', 1000, 3);

if (empty($results)) {
    echo "FAILURE: No results returned from Amazon.\n";
} else {
    echo "SUCCESS: Found " . count($results) . " items.\n";
    foreach ($results as $item) {
        echo "- {$item['title']} : {$item['price_formatted']} ({$item['source']})\n";
    }
}

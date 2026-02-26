<?php
define('LAK24_BOT', true);
require_once __DIR__ . '/../classes/Cache.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/OfferSearch.php';

$config = require __DIR__ . '/config.php';
$logger = new Logger($config['logging']);
$cache = new Cache($config['cache']);
$search = new OfferSearch($config['search'], $cache, $logger);

$query = "Laptop"; // Simulation of extracted keyword
echo "Searching for: $query\n";

$results = $search->search($query, 700);

echo "Found " . count($results['results']) . " results.\n";
foreach ($results['results'] as $r) {
    echo "- " . $r['title'] . " (" . $r['price_formatted'] . ")\n";
}

// Test accessory detection
$testTitle = "Laptop sleeve Case Bag";
$isAcc = (new ReflectionMethod('OfferSearch', 'isAccessory'))->setAccessible(true);
$isAccResult = (new ReflectionClass('OfferSearch'))->newInstanceWithoutConstructor()->isAccessory($testTitle);

echo "\nTest accessory detection for '$testTitle': " . ($isAccResult ? "YES" : "NO") . "\n";

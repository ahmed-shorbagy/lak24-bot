<?php
define('LAK24_BOT', true);

require_once 'classes/Env.php';
Env::load(__DIR__ . '/.env');

require_once 'classes/Logger.php';
require_once 'classes/Cache.php';
require_once 'classes/AwinDatabase.php';
require_once 'classes/OfferSearch.php';

$config = require 'config.php';
$logger = new Logger($config['logging']);
$cache  = new Cache($config['cache']);

// Clear cache first
foreach(glob('cache/*.cache') as $f) @unlink($f);

$search = new OfferSearch($config, $cache, $logger);

echo "=== TEST 1: Smartphone search WITH variants ===\n";
$variants = [
    'Samsung Galaxy Smartphone',
    'iPhone Apple',
    'Xiaomi Redmi Smartphone',
    'Google Pixel',
    'OnePlus Smartphone',
    'Motorola Smartphone',
];
$results = $search->search('Smartphone', 400, '', $variants);
echo "Found: " . $results['total_found'] . " results\n";
foreach ($results['results'] as $i => $r) {
    echo ($i+1) . ". " . $r['title'] . " — " . ($r['price_formatted'] ?? $r['price']) . " (" . $r['source'] . ")\n";
}

echo "\n=== TEST 2: Laptop search WITH variants ===\n";
$variants2 = [
    'Lenovo Laptop Notebook',
    'HP Laptop Notebook',
    'Dell Laptop',
    'ASUS Laptop',
    'Acer Laptop Notebook',
    'Apple MacBook',
];
// Clear cache for this query
foreach(glob('cache/*.cache') as $f) @unlink($f);
$results2 = $search->search('Laptop', 700, '', $variants2);
echo "Found: " . $results2['total_found'] . " results\n";
foreach ($results2['results'] as $i => $r) {
    echo ($i+1) . ". " . $r['title'] . " — " . ($r['price_formatted'] ?? $r['price']) . " (" . $r['source'] . ")\n";
}

echo "\n=== TEST 3: Monitor search WITH variants ===\n";
$variants3 = [
    'Samsung Monitor',
    'LG Monitor',
    'ASUS Monitor',
    'Dell Monitor',
    'AOC Monitor',
    'BenQ Monitor',
];
foreach(glob('cache/*.cache') as $f) @unlink($f);
$results3 = $search->search('Monitor', null, '', $variants3);
echo "Found: " . $results3['total_found'] . " results\n";
foreach ($results3['results'] as $i => $r) {
    echo ($i+1) . ". " . $r['title'] . " — " . ($r['price_formatted'] ?? $r['price']) . " (" . $r['source'] . ")\n";
}

echo "\nDONE\n";

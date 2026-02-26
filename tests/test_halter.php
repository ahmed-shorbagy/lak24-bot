<?php
define('LAK24_BOT', true);

// Minimal stubs
class Cache { public function get($k) { return null; } public function set($k, $v, $t) {} public static function makeKey() { return "key"; } }
class Logger { public function info($m, $c=[]) { echo "[INFO] $m " . json_encode($c) . "\n"; } public function warning($m, $c=[]) {} public function error($m, $c=[]) {} }

require_once __DIR__ . '/../classes/OfferSearch.php';

$search = new OfferSearch([
    'max_results' => 5,
    'lak24_priority' => 3,
    'lak24_base_url' => 'https://lak24.de',
    'search' => [],
    'affiliate' => []
], new Cache(), new Logger());

$rc = new ReflectionClass($search);
$isAcc = $rc->getMethod('isAccessory');
$isAcc->setAccessible(true);

$title = "RHODIA Smartphonehalter";
$res = $isAcc->invoke($search, $title);

echo "Title: $title\n";
echo "Is Accessory: " . ($res ? "YES" : "NO") . "\n";

$titleLower = mb_strtolower($title);
echo "Lowercased: $titleLower\n";
echo "Pos of 'halter': " . mb_strpos($titleLower, 'halter') . "\n";

<?php
define('LAK24_BOT', true);

// Minimal stubs
class Cache { public function get($k) { return null; } public function set($k, $v, $t) {} public static function makeKey() { return "key"; } }
class Logger { public function info($m, $c=[]) {} public function warning($m, $c=[]) {} public function error($m, $c=[]) {} }

require_once __DIR__ . '/../classes/OfferSearch.php';

$search = new OfferSearch([
    'max_results' => 5,
    'lak24_priority' => 3,
    'lak24_base_url' => 'https://lak24.de',
    'search' => [],
    'affiliate' => []
], new Cache(), new Logger());

// Use reflection to access private methods
$rc = new ReflectionClass($search);
$isAcc = $rc->getMethod('isAccessory');
$isAcc->setAccessible(true);
$isAccReq = $rc->getMethod('isAccessoryRequested');
$isAccReq->setAccessible(true);

// ---- Test the EXACT titles from the user's failed result ----
echo "=== Testing EXACT titles from user's bad results ===\n\n";

$realTitles = [
    "Fellowes Laptop-Arm Ergänzung Vista"                          => true,
    "Equip 133456 laptop-dockingstation & portreplikator Schwarz"   => true,
    "EXACOMPTA Laptophülle Business, 13-14, schwarz"               => true,
    "EXACOMPTA Laptophülle Business, 13-14, grau"                  => true,
    "EXACOMPTA Laptophülle Business, 13-14, blau"                  => true,
    // Actual laptops — these should NOT be filtered
    "Microsoft Surface Laptop 2"                                    => false,
    "Lenovo IdeaPad 3 15ITL6 Laptop"                               => false,
    "Dell Latitude 5420 Notebook"                                   => false,
    "HP ProBook 450 G8 Laptop"                                     => false,
    // Mobile accessories
    "Panzerglas für iPhone 15"                                     => true,
    "Samsung Galaxy S23 Silikon Hülle"                             => true,
    "USB-C Schnellladegerät 25W"                                   => true,
    "Anker Powercore Powerbank 20000"                              => true,
    // Screen accessories
    "TV Wandhalterung schwenkbar"                                  => true,
    "Monitorständer höhenverstellbar"                              => true,
    "Highspeed HDMI Kabel 2.1"                                     => true,
    "Ersatzfernbedienung für LG TV"                                => true,
    // Actual hardware
    "Apple iPhone 15 Pro Max"                                      => false,
    "Samsung Galaxy S24 Ultra"                                     => false,
    "LG OLED 55 Zoll Fernseher"                                    => false,
    "Dell UltraSharp Monitor 27 Zoll"                              => false,
    "Sony BRAVIA 4K Smart TV"                                      => false,
    // Stationery / Noise
    "Pentel Textmarker Handy-lineS SXS15"                          => true,
    "SMARTBOXPRO PP-Umreifungs-Set, Handy"                         => true,
    "Büro-Mappe für Dokumente"                                     => true,
    "Display-Reinigungstücher 100er Pack"                          => true,
    "RHODIA Smartphonehalter Holz"                                 => true,
    "Umzugskarton XL Box"                                          => true,
];

$failed = 0;
foreach ($realTitles as $title => $expectedAccessory) {
    $result = $isAcc->invoke($search, $title);
    $pass = ($result === $expectedAccessory);
    $status = $pass ? "PASS" : "FAIL";
    if (!$pass) $failed++;
    
    $label = $expectedAccessory ? "ACCESSORY" : "PRODUCT";
    $got   = $result ? "ACCESSORY" : "PRODUCT";
    echo sprintf("[%s] %-60s expected=%-10s got=%-10s\n", $status, $title, $label, $got);
}

echo "\n=== Testing isAccessoryRequested ===\n";
$queryTests = [
    "Laptop"       => false,
    "Laptop Case"  => true,
    "Laptop hülle" => true,
    "Notebook"     => false,
];

foreach ($queryTests as $q => $exp) {
    $res = $isAccReq->invoke($search, $q);
    $pass = ($res === $exp);
    echo sprintf("[%s] Query: %-20s expected=%s got=%s\n", $pass?"PASS":"FAIL", $q, $exp?"YES":"NO", $res?"YES":"NO");
    if (!$pass) $failed++;
}

echo "\n" . ($failed === 0 ? "ALL TESTS PASSED ✓" : "$failed TEST(S) FAILED ✗") . "\n";
exit($failed > 0 ? 1 : 0);

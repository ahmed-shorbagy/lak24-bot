<?php
define('LAK24_BOT', true);

$pdo = new PDO('sqlite:data/awin_products.db');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Top 20 results for 'Smartphone' ===\n";
$stmt = $pdo->prepare("SELECT title, price, source FROM awin_products WHERE awin_products MATCH ? ORDER BY rank LIMIT 20");
$stmt->execute(['"Smartphone"*']);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo number_format($r['price'], 2) . " | " . $r['source'] . " | " . $r['title'] . "\n";
}

echo "\n=== Top 20 results for 'Samsung Galaxy' ===\n";
$stmt->execute(['"Samsung" AND "Galaxy"']);
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo number_format($r['price'], 2) . " | " . $r['source'] . " | " . $r['title'] . "\n";
}

echo "\n=== Top 20 results for 'iPhone' ===\n";
$stmt2 = $pdo->prepare("SELECT title, price, source FROM awin_products WHERE awin_products MATCH ? ORDER BY rank LIMIT 20");
$stmt2->execute(['"iPhone"*']);
while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo number_format($r['price'], 2) . " | " . $r['source'] . " | " . $r['title'] . "\n";
}

echo "\n=== Total products ===\n";
$stmt3 = $pdo->query("SELECT COUNT(*) FROM awin_products");
echo $stmt3->fetchColumn() . " products total\n";

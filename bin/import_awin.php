<?php
#!/usr/bin/env php
/**
 * Awin Data Feed Importer CLI
 * 
 * Run this script to download the latest Awin product feed and 
 * insert it into the local SQLite FTS5 database for fast searching.
 * 
 * Usage: php bin/import_awin.php
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.\n");
}

define('LAK24_BOT', true);

$config = require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/AwinDatabase.php';

echo "========================================\n";
echo " lak24 Awin Data Feed Importer\n";
echo "========================================\n\n";

if (empty($config['affiliate']['awin']['data_feed_url'])) {
    echo "[ERROR] Awin data feed URL not configured in config.php\n";
    exit(1);
}

$logger = new Logger($config['logging'] ?? ['path' => __DIR__ . '/../logs', 'level' => 'debug']);
$awin   = new AwinDatabase($config['affiliate']['awin'], $logger);

echo "Connecting to Awin and downloading feed...\n";
echo "This may take a few minutes depending on the feed size.\n\n";

$startTime = microtime(true);
$count = $awin->importFeed();
$duration = round(microtime(true) - $startTime, 2);

if ($count > 0) {
    echo "[SUCCESS] Import complete!\n";
    echo "Imported: $count products\n";
    echo "Time taken: $duration seconds\n";
} else {
    echo "[FAILED] Import failed or 0 products found.\n";
    echo "Check the logs in /logs for more details.\n";
    exit(1);
}

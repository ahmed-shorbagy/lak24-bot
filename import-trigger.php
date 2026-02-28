<?php
/**
 * Awin Import Trigger
 * 
 * Access this file via browser to manually populate the SQLite database.
 * Protect this file or delete it after use!
 */

define('LAK24_BOT', true);
$config = require_once __DIR__ . '/config.php';
require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/classes/AwinDatabase.php';

// Increase limits for large feed processing
set_time_limit(0); 
ini_set('memory_limit', '512M');
ignore_user_abort(true);

header('Content-Type: text/plain; charset=utf-8');

echo "Starting Awin Import...\n";
echo "---------------------------\n";

try {
    $logger = new Logger($config['logging']);
    $awin   = new AwinDatabase($config['affiliate']['awin'], $logger);

    // Disable buffering
    if (ob_get_level()) ob_end_clean();
    ob_implicit_flush(true);

    $count = $awin->importFeed(function($currentCount) {
        echo "Progress: Imported $currentCount products...\n";
        @flush();
    });

    if ($count > 0) {
        echo "SUCCESS: Imported $count products.\n";
        echo "The bot should now be able to fetch real offers.\n";
    } else {
        echo "FAILED: 0 products imported. Check logs/ folder for errors.\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "---------------------------\n";
echo "Done.\n";

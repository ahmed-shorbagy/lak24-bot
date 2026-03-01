<?php
/**
 * Clear the offer search cache.
 * Access: GET /clear_cache.php
 */
define('LAK24_BOT', true);
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/classes/Cache.php';

$cache = new Cache($config['cache']);
$deleted = $cache->clear();

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => "Cleared {$deleted} cached entries.",
    'deleted' => $deleted,
]);

<?php
define('LAK24_BOT', true);
$config = require 'config.php';

echo "OpenAI Model: " . ($config['openai']['model'] ?? 'N/A') . "\n";
echo "OpenAI Key Length: " . strlen($config['openai']['api_key'] ?? '') . "\n";
echo "Amazon Access Key Length: " . strlen($config['affiliate']['amazon']['access_key'] ?? '') . "\n";
echo "Amazon Store ID: " . ($config['affiliate']['amazon']['store_id'] ?? 'N/A') . "\n";

if (strlen($config['openai']['api_key'] ?? '') > 0 && $config['openai']['model'] === 'gpt-4o-mini') {
    echo "VERIFICATION SUCCESS: Environment variables loaded correctly.\n";
} else {
    echo "VERIFICATION FAILURE: Environment variables not loaded.\n";
}

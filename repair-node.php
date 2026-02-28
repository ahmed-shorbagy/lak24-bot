<?php
/**
 * lak24 AI Chatbot — Node Dependency Repair Tool
 * 
 * Performs a clean "npm install" using your local Node engine.
 * Solves "Failed to load native binding" by installing Linux versions.
 */

define('LAK24_BOT', true);
header('Content-Type: text/plain; charset=utf-8');

echo "===============================================\n";
echo "🤖 lak24 AI Chatbot - Dependency Repair Tool\n";
echo "===============================================\n\n";

$binDir = __DIR__ . '/bin';
$nodePath = $binDir . '/node';
$npmPath  = $binDir . '/npm';

if (!file_exists($nodePath) || !file_exists($npmPath)) {
    echo "DEBUG: Checked $nodePath and $npmPath\n";
    die("❌ Error: Local Node.js or npm not found. Please run the NEW 'install-node.php' first!\n");
}

echo "1. Removing broken Windows node_modules...\n";
if (is_dir(__DIR__ . '/node_modules')) {
    function deleteDir($dirPath) {
        if (!is_dir($dirPath)) return;
        $files = array_diff(scandir($dirPath), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$dirPath/$file") && !is_link("$dirPath/$file")) ? deleteDir("$dirPath/$file") : @unlink("$dirPath/$file");
        }
        return @rmdir($dirPath);
    }
    deleteDir(__DIR__ . '/node_modules');
}

@unlink(__DIR__ . '/package-lock.json');
echo "   ✅ Cleanup complete.\n";

echo "\n2. Performing Linux Installation (this takes 1-2 minutes)...\n";
set_time_limit(300);

// Setup a local cache folder to avoid permission issues
$cache = $binDir . '/npm_cache';
if (!is_dir($cache)) @mkdir($cache, 0755, true);

// We invoke the npm shim using 'sh' to be safe
$cmd = "export HOME=" . escapeshellarg($cache) . "; sh " . escapeshellarg($npmPath) . " install --no-audit --no-fund 2>&1";
exec($cmd, $output, $returnCode);

if ($returnCode === 0) {
    echo "Successfully installed components.\n";
} else {
    echo "Standard installation failed, attempting forced rebuild...\n";
    $cmd = "export HOME=" . escapeshellarg($cache) . "; sh " . escapeshellarg($npmPath) . " rebuild 2>&1";
    exec($cmd, $output, $returnCode);
}

echo "Final Output:\n" . implode("\n", $output) . "\n";

if (is_dir(__DIR__ . '/node_modules/pdf-parse')) {
    echo "\n🎉 SUCCESS! pdf-parse is now correctly installed for Linux.\n";
    echo "Test your PDF upload now! 🚀\n";
} else {
    echo "\n❌ ERROR: node_modules/pdf-parse was not created. Check output above.\n";
}

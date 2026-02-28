<?php
/**
 * Node.js Diagnostic Tool
 * Upload this to your server to see if Node is working.
 */
define('LAK24_BOT', true);
header('Content-Type: text/plain; charset=utf-8');

echo "--- Node.js Diagnostic ---\n\n";

// 1. Check PHP functions & Environment
echo "Checking PHP Environment:\n";
echo "exec(): " . (function_exists('exec') ? "YES" : "NO") . "\n";
echo "shell_exec(): " . (function_exists('shell_exec') ? "YES" : "NO") . "\n";
echo "node_modules folder: " . (is_dir(__DIR__ . '/node_modules') ? "FOUND" : "NOT FOUND") . "\n";

// 2. Try to find node path
echo "\nTrying to find 'node' path:\n";
$paths = [
    __DIR__ . '/bin/node', // Local standalone
    'node', 
    'node18', 'node20',
    '/usr/local/bin/node', '/usr/bin/node'
];
foreach ($paths as $path) {
    unset($output);
    @exec("$path -v 2>&1", $output, $returnCode);
    $ver = $output[0] ?? 'N/A';
    if ($returnCode === 0) {
        echo "✅ $path: FOUND ($ver)\n";
        $workingNode = $path;
    } else {
        echo "❌ $path: NOT FOUND\n";
    }
}

// 3. Test pdf-extract.js execution if node found
if (isset($workingNode)) {
    echo "\nTesting actual script execution with $workingNode:\n";
    $script = __DIR__ . '/bin/pdf-extract.js';
    if (!file_exists($script)) {
        echo "ERROR: bin/pdf-extract.js not found at $script\n";
    } else {
        echo "Found script at $script\n";
        unset($output);
        exec("$workingNode " . escapeshellarg($script) . " non_existent.pdf 2>&1", $output, $return);
        echo "Return Code: $return\n";
        echo "Output: " . implode("\n", $output) . "\n";
    }
} else {
    echo "\nCRITICAL: No node runtime found. Please contact your host or use the improved PHP extraction below.\n";
}

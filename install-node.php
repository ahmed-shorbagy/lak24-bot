<?php
/**
 * lak24 AI Chatbot — Automated Local Node.js Installer
 * 
 * Specifically designed to run "pdf-extract.js" perfectly on shared hosting (like IONOS)
 * by downloading a standalone Linux x64 Node.js binary into the `bin/` folder.
 */

define('LAK24_BOT', true);
header('Content-Type: text/plain; charset=utf-8');

echo "===============================================\n";
echo "🤖 lak24 AI Chatbot - Local Node.js setup tool\n";
echo "===============================================\n\n";

$binDir = __DIR__ . '/bin';
if (!is_dir($binDir)) {
    mkdir($binDir, 0755, true);
}

$nodePath = $binDir . '/node';

if (file_exists($nodePath)) {
    echo "✅ Success! Node.js is already installed at: $nodePath\n";
    exec(escapeshellarg($nodePath) . " -v 2>&1", $out, $code);
    echo "Version: " . ($out[0] ?? 'Unknown') . "\n";
    echo "You are ready to process PDFs!\n";
    exit;
}

echo "1. Checking System Architecture...\n";
$os = php_uname('s');
$arch = php_uname('m');
echo "   OS: $os\n   Architecture: $arch\n\n";

if (stripos($os, 'linux') === false || ($arch !== 'x86_64' && $arch !== 'amd64')) {
    die("❌ This automated installer only supports Linux x64 servers (standard for IONOS web hosting). Your server is $os $arch.\n");
}

$nodeVersion = 'v20.11.1';
$downloadUrl = "https://nodejs.org/dist/{$nodeVersion}/node-{$nodeVersion}-linux-x64.tar.xz";
$tmpFile = $binDir . '/node.tar.xz';

echo "2. Downloading Node.js $nodeVersion directly from nodejs.org (this may take up to a minute)...\n";
set_time_limit(120);

$ch = curl_init($downloadUrl);
$fp = fopen($tmpFile, 'wb');
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);
fclose($fp);

if (!file_exists($tmpFile) || filesize($tmpFile) < 1000000 || $error) {
    if (file_exists($tmpFile)) unlink($tmpFile);
    die("❌ Failed to download Node.js tarball. Error: $error\n");
}
echo "   Download complete. Size: " . round(filesize($tmpFile) / 1024 / 1024, 2) . " MB\n\n";

echo "3. Extracting Node.js binary...\n";
// Ensure tar is available
exec("tar --version 2>&1", $tarCheck, $tarCode);
if ($tarCode !== 0) {
    unlink($tmpFile);
    die("❌ The 'tar' command is not available on your host to extract the file.\n");
}

// Extract only the node executable from the archive
$command = "tar -xf " . escapeshellarg($tmpFile) . " -C " . escapeshellarg($binDir) . " --strip-components=2 node-{$nodeVersion}-linux-x64/bin/node 2>&1";
exec($command, $tarOut, $tarCode);

if ($tarCode !== 0 || !file_exists($nodePath)) {
    unlink($tmpFile);
    die("❌ Extraction failed. Output: " . implode("\n", $tarOut) . "\n");
}

echo "   Extraction complete.\n\n";

echo "4. Setting execute permissions...\n";
chmod($nodePath, 0755);
unlink($tmpFile); // Clean up the big archive

echo "5. Testing Node.js Execution...\n";
exec(escapeshellarg($nodePath) . " -v 2>&1", $testOut, $testCode);

if ($testCode === 0) {
    echo "✅ SUCCESS! Node.js locally installed!\n";
    echo "   Node Version: " . ($testOut[0] ?? 'Unknown') . "\n";
    echo "   Path: $nodePath\n\n";
    echo "🎉 You can now translate complex PDFs perfectly using JS directly on your server!\n";
} else {
    echo "❌ Execution failed.\n   Your host might block executing local standalone binaries (e.g. 'noexec' mount).\n   Output: " . implode("\n", $testOut) . "\n";
    unlink($nodePath); // Remove broken binary
}

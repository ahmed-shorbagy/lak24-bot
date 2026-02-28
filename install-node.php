<?php
/**
 * lak24 AI Chatbot — Automated Local Node.js Installer (v18 Compatibility Mode)
 * 
 * Specifically designed to run "pdf-extract.js" perfectly on older servers
 * by using Node v18 (More stable on shared hosts like IONOS).
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
$npmPath  = $binDir . '/npm';

echo "Running COMPATIBILITY installation (Node v18 + Shim Fix)...\n\n";

echo "1. Checking Architecture...\n";
$os = php_uname('s');
$arch = php_uname('m');
echo "   OS: $os\n   Architecture: $arch\n\n";

// Use Node 18.x as it has lower glibc requirements than 20.x
$nodeVersion = 'v18.19.1'; 
$downloadUrl = "https://nodejs.org/dist/{$nodeVersion}/node-{$nodeVersion}-linux-x64.tar.xz";
$tmpFile = $binDir . '/node.tar.xz';

echo "2. Downloading Node.js $nodeVersion (Maximum Compatibility)...\n";
set_time_limit(180);

$ch = curl_init($downloadUrl);
$fp = fopen($tmpFile, 'wb');
curl_setopt($ch, CURLOPT_FILE, $fp);
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_exec($ch);
curl_close($ch);
fclose($fp);

if (!file_exists($tmpFile) || filesize($tmpFile) < 5000000) {
    die("❌ Download failed.\n");
}
echo "   ✅ Download complete.\n\n";

echo "3. Extracting Binaries...\n";

// 1. Extract the actual Node binary
echo "   Extracting node binary... ";
$cmd = "tar -xf " . escapeshellarg($tmpFile) . " -C " . escapeshellarg($binDir) . " --strip-components=2 node-{$nodeVersion}-linux-x64/bin/node 2>&1";
exec($cmd, $out, $code);
if ($code === 0 && file_exists($nodePath)) {
    chmod($nodePath, 0755);
    echo "✅ DONE\n";
} else {
    die("❌ FAILED EXTRACTION\n");
}

// 2. Extract Core Libraries (to bin/node_modules)
echo "   Extracting npm core... ";
$cmd = "tar -xf " . escapeshellarg($tmpFile) . " -C " . escapeshellarg($binDir) . " --strip-components=2 \"node-{$nodeVersion}-linux-x64/lib/node_modules\" 2>&1";
exec($cmd, $out2, $code2);
if ($code2 === 0 && is_dir($binDir . '/node_modules/npm')) {
    echo "✅ DONE\n";
} else {
    echo "❌ FAILED (libraries)\n";
}

// 3. Create Fixed Shims
echo "   Creating fixed npm shim... ";
$npmShim = "#!/bin/sh\n";
$npmShim .= 'BASEDIR=$(dirname "$0")' . "\n";
$npmShim .= '"$BASEDIR/node" "$BASEDIR/node_modules/npm/bin/npm-cli.js" "$@"' . "\n";
file_put_contents($npmPath, $npmShim);
chmod($npmPath, 0755);

$npxShim = "#!/bin/sh\n";
$npxShim .= 'BASEDIR=$(dirname "$0")' . "\n";
$npxShim .= '"$BASEDIR/node" "$BASEDIR/node_modules/npm/bin/npx-cli.js" "$@"' . "\n";
file_put_contents($binDir . '/npx', $npxShim);
chmod($binDir . '/npx', 0755);
echo "✅ DONE\n";

@unlink($tmpFile);

echo "\n4. Testing Node Execution...\n";
exec(escapeshellarg($nodePath) . " -v 2>&1", $nodeOut, $nodeCode);
if ($nodeCode === 0) {
    echo "   ✅ Node.js v18 is RUNNING! (Version: " . $nodeOut[0] . ")\n";
    echo "   Wait... testing npm shim... ";
    exec("sh " . escapeshellarg($npmPath) . " -v 2>&1", $npmOut, $npmCode);
    if ($npmCode === 0) {
        echo "✅ npm is READY!\n";
        echo "\n🎉 SUCCESS! Now run 'repair-node.php' to fix your PDFs.\n";
    } else {
        echo "❌ npm FAILED (" . ($npmOut[0] ?? 'No output') . ")\n";
    }
} else {
    echo "❌ Node.js v18 also Segfaulted. Your host is extremely restricted.\n";
    echo "   I will pivot to the PHP-only solution if this happens.\n";
}

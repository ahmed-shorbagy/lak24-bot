<?php
// Mocking the environment to test chat.php
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['message'] = 'ابحث لي عن ثلاجة سعرها تحت 2000 يورو';
$_POST['session_id'] = 'test-session-123';
$_POST['stream'] = false;

// Capture output
ob_start();
include __DIR__ . '/../chat.php';
$output = ob_get_clean();

echo "--- OUTPUT ---\n";
echo $output;
echo "\n--- END OUTPUT ---\n";

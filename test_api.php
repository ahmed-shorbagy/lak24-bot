<?php
$url = 'http://localhost/Chatbot/chat.php';
$data = ['message' => 'I want to buy a laptop for under 500 euros'];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data)
    ]
];
$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

if ($result === FALSE) {
    echo "Error calling API\n";
    exit(1);
}

$decoded = json_decode($result, true);
file_put_contents('test_api_out_fixed.txt', json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "DONE\n";

<?php
declare(strict_types=1);

$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => json_encode(['username' => 'customer', 'password' => 'Customer123!']),
    'timeout' => 5,
    'ignore_errors' => true
]]);
$loginBody = file_get_contents('http://auth-service:8080/login', false, $context);
$login = json_decode((string)$loginBody, true);
if (!is_array($login) || !isset($login['access_token'])) {
    fwrite(STDERR, "Unable to authenticate against auth-service.\n");
    exit(2);
}
$requestId = 'crash-test-' . bin2hex(random_bytes(6));
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$login['access_token']}\r\nIdempotency-Key: {$requestId}\r\n",
    'content' => json_encode(['product_id' => 3, 'quantity' => 1]),
    'timeout' => 8,
    'ignore_errors' => true
]]);
$body = file_get_contents('http://order-service:8080/orders', false, $context);
$status = 0;
foreach ($http_response_header ?? [] as $header) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
        $status = (int)$match[1];
    }
}
if ($status !== 503) {
    fwrite(STDERR, "Expected HTTP 503 when Inventory is stopped; got {$status}: {$body}\n");
    exit(1);
}
echo json_encode(['expected_failure' => true, 'http_status' => $status, 'response' => json_decode((string)$body, true)]) . PHP_EOL;

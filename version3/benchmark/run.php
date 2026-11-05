<?php
declare(strict_types=1);

function callApi(string $method, string $url, array $data = [], ?string $token = null, ?string $requestId = null): array
{
    $headers = "Content-Type: application/json\r\n";
    if ($token !== null) {
        $headers .= "Authorization: Bearer {$token}\r\n";
    }
    if ($requestId !== null) {
        $headers .= "X-Request-ID: {$requestId}\r\n";
    }
    $options = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $method === 'GET' ? '' : json_encode($data),
        'timeout' => 10,
        'ignore_errors' => true
    ]]);
    $body = file_get_contents($url, false, $options);
    if ($body === false) {
        throw new RuntimeException('Request failed: ' . $url);
    }
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || $status < 200 || $status >= 300) {
        throw new RuntimeException('API returned HTTP ' . $status . ': ' . $body);
    }
    return $decoded;
}

$count = isset($argv[1]) ? filter_var($argv[1], FILTER_VALIDATE_INT) : 5;
if (!$count || $count < 1 || $count > 10) {
    fwrite(STDERR, "Usage: php run.php [iterations: 1..10]\n");
    exit(2);
}
$login = callApi('POST', 'http://auth-service:8080/login', ['username' => 'customer', 'password' => 'Customer123!']);
$token = $login['access_token'];
$durations = [];
for ($i = 0; $i < $count; $i++) {
    $started = hrtime(true);
    callApi('POST', 'http://order-service:8080/orders', [
        'product_id' => 3,
        'quantity' => 1,
        'idempotency_key' => 'bench-v3-' . bin2hex(random_bytes(6))
    ], $token, 'bench-v3-' . bin2hex(random_bytes(6)));
    $durations[] = (hrtime(true) - $started) / 1_000_000;
}
sort($durations);
$p95Index = min(count($durations) - 1, (int)ceil(count($durations) * 0.95) - 1);
echo json_encode([
    'architecture' => 'v3-docker-microservices',
    'iterations' => $count,
    'unit' => 'ms',
    'measurements' => array_map(static fn(float $value): float => round($value, 3), $durations),
    'median_ms' => round($durations[(int)floor((count($durations) - 1) / 2)], 3),
    'p95_ms' => round($durations[$p95Index], 3),
    'scope' => 'Order HTTP request including auth validation, inventory, payment and delivery; login excluded'
], JSON_UNESCAPED_SLASHES) . PHP_EOL;

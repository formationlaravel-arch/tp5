<?php
declare(strict_types=1);

function sendJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function readJson(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        sendJson(['error' => 'Expected a JSON object'], 400);
    }
    return $data;
}

function requestId(): string
{
    $value = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    return preg_match('/^[A-Za-z0-9._-]{1,120}$/', $value) ? $value : bin2hex(random_bytes(12));
}

function reportEvent(string $service, string $level, string $message, string $requestId): void
{
    $line = json_encode(['service' => $service, 'level' => $level, 'message' => $message, 'request_id' => $requestId]);
    error_log($line === false ? $message : $line);
    $url = getenv('LOG_SERVICE_URL') ?: '';
    if ($url === '') {
        return;
    }
    $body = json_encode(['service' => $service, 'level' => $level, 'message' => $message, 'request_id' => $requestId]);
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-Request-ID: {$requestId}\r\n",
        'content' => $body,
        'timeout' => 1.5,
        'ignore_errors' => true
    ]]);
    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        error_log('Central log service unavailable; event retained in container stdout.');
    }
}

function httpJson(string $method, string $url, array $payload = [], ?string $bearer = null, ?string $requestId = null): array
{
    $headers = "Content-Type: application/json\r\n";
    if ($bearer !== null) {
        $headers .= "Authorization: Bearer {$bearer}\r\n";
    }
    if ($requestId !== null) {
        $headers .= "X-Request-ID: {$requestId}\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => $headers,
        'content' => $method === 'GET' ? '' : json_encode($payload),
        'timeout' => 2,
        'ignore_errors' => true
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    if ($body === false || $status === 0) {
        throw new RuntimeException('Upstream timeout or connection failure: ' . $url);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response from upstream: ' . $url);
    }
    return ['status' => $status, 'body' => $decoded];
}

function authorize(string $authUrl): array
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/i', $header, $match)) {
        sendJson(['error' => 'Bearer token required'], 401);
    }
    try {
        $result = httpJson('GET', $authUrl . '/validate', [], $match[1], requestId());
    } catch (RuntimeException $exception) {
        sendJson(['error' => 'Authentication service unavailable'], 503);
    }
    if ($result['status'] !== 200 || ($result['body']['valid'] ?? false) !== true) {
        sendJson(['error' => 'Invalid or expired token'], 401);
    }
    return ['token' => $match[1], 'claims' => $result['body']];
}

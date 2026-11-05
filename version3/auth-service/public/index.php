<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(12));
header('X-Request-ID: ' . $requestId);

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    respond(['status' => 'ok']);
}
$secret = getenv('JWT_SECRET') ?: '';
if (strlen($secret) < 32) {
    respond(['error' => 'JWT secret must be at least 32 characters'], 500);
}
$body = json_decode(file_get_contents('php://input'), true);

if ($path === '/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $users = [
        'admin' => ['password_hash' => '$2y$12$iPPBxhL/wZ6oLlnySH6U7eEwa3iqa5.eRIh.eoiefzRPzqM.GgF4.', 'role' => 'ADMIN', 'id' => 1],
        'inventory' => ['password_hash' => '$2y$12$/nVQ.FmeGRzZs7KkL0GFe.e.Xes2BV8zdawLH4jjjXqXRyGVwxWry', 'role' => 'INVENTORY_MANAGER', 'id' => 2],
        'orders' => ['password_hash' => '$2y$12$DaXagn6Dj53E3PVMJMp67u/auXMCJmRSqAIzxjl/E7J6U0NoVaFPO', 'role' => 'ORDER_MANAGER', 'id' => 3],
        'delivery' => ['password_hash' => '$2y$12$HjfTOY/tZluKaQOLc1LdW.rWERQRkahOumRsEdV1Sef5rkTtToHg.', 'role' => 'DELIVERY_MANAGER', 'id' => 4],
        'finance' => ['password_hash' => '$2y$12$2JpoYLrLFTMh3r7sIpkHoODWk28zIbtvu5zGdyzxPZ2wjxw.ZQasq', 'role' => 'FINANCE_OFFICER', 'id' => 5],
        'customer' => ['password_hash' => '$2y$12$GrNZyq3aaWtNYabLdkh2PeGdlaLXLrQjKeFl8d6D.3aRyOJxOi9ry', 'role' => 'CUSTOMER', 'id' => 6]
    ];
    $username = is_array($body) ? (string)($body['username'] ?? '') : '';
    $password = is_array($body) ? (string)($body['password'] ?? '') : '';
    if (!isset($users[$username]) || !password_verify($password, $users[$username]['password_hash'])) {
        respond(['error' => 'Invalid credentials'], 401);
    }
    $now = time();
    $token = JWT::encode([
        'iss' => 'mpin-auth-service',
        'sub' => $users[$username]['id'],
        'username' => $username,
        'role' => $users[$username]['role'],
        'iat' => $now,
        'exp' => $now + 3600
    ], $secret, 'HS256');
    respond(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600]);
}

if ($path === '/validate' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/i', $header, $match)) {
        respond(['error' => 'Bearer token required'], 401);
    }
    try {
        $claims = (array)JWT::decode($match[1], new Key($secret, 'HS256'));
        if (($claims['iss'] ?? '') !== 'mpin-auth-service') {
            respond(['error' => 'Invalid issuer'], 401);
        }
        respond(['valid' => true, 'sub' => (int)$claims['sub'], 'username' => $claims['username'], 'role' => $claims['role']]);
    } catch (Throwable $exception) {
        respond(['error' => 'Invalid or expired token'], 401);
    }
}
respond(['error' => 'Not found'], 404);

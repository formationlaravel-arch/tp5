<?php
declare(strict_types=1);

require '/app/common/http.php';
$requestId = requestId();
header('X-Request-ID: ' . $requestId);

function database(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $host = getenv('DB_HOST') ?: 'order-db';
        $name = getenv('DB_NAME') ?: 'orders_db';
        $pdo = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            getenv('DB_USER') ?: 'orders_app',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $statusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch();
        if ($statusColumn && strpos((string)$statusColumn['Type'], "'PAID'") === false) {
            $pdo->exec("ALTER TABLE orders MODIFY status ENUM('PENDING','PAID','SHIPPED','DELIVERED','CANCELLED','COMPLETED','FAILED') NOT NULL DEFAULT 'PENDING'");
        }
    }
    return $pdo;
}

function upstream(string $name, string $url, string $method, array $payload, string $token, string $requestId): array
{
    $result = httpJson($method, $url, $payload, $token, $requestId);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException($name . ' rejected request: ' . ($result['body']['error'] ?? 'HTTP ' . $result['status']));
    }
    return $result['body'];
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    sendJson(['status' => 'ok', 'service' => 'order-service']);
}

$identity = authorize(getenv('AUTH_URL') ?: 'http://auth-service:8080');
$role = (string)($identity['claims']['role'] ?? '');

if ($path === '/orders' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!in_array($role, ['CUSTOMER', 'ORDER_MANAGER', 'DELIVERY_MANAGER', 'FINANCE_OFFICER', 'ADMIN'], true)) {
        sendJson(['error' => 'Authenticated e-commerce role required'], 403);
    }
    $pdo = database();
    if (in_array($role, ['ORDER_MANAGER', 'DELIVERY_MANAGER', 'FINANCE_OFFICER', 'ADMIN'], true)) {
        $rows = $pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $statement = $pdo->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 50');
        $statement->execute([(int)$identity['claims']['sub']]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    sendJson(['data' => $rows]);
}

if ($path !== '/orders' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (preg_match('#^/orders/(\d+)/status$#', $path, $matches) && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!in_array($role, ['ORDER_MANAGER', 'ADMIN'], true)) {
            sendJson(['error' => 'ORDER_MANAGER or ADMIN role required'], 403);
        }
        $input = readJson();
        $status = (string)($input['status'] ?? 'PENDING');
        if (!in_array($status, ['PENDING', 'PAID', 'SHIPPED', 'CANCELLED'], true)) {
            sendJson(['error' => 'Invalid status value'], 400);
        }
        $pdo = database();
        $statement = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $statement->execute([$status, (int)$matches[1]]);
        sendJson(['data' => ['id' => (int)$matches[1], 'status' => $status]]);
    }
    sendJson(['error' => 'Not found'], 404);
}

if (($identity['claims']['role'] ?? '') !== 'CUSTOMER') {
    sendJson(['error' => 'CUSTOMER role required'], 403);
}
$input = readJson();
$productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
$quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
$key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($input['idempotency_key'] ?? '');
if (!$productId || !$quantity || $quantity < 1 || $quantity > 20 || !preg_match('/^[A-Za-z0-9._-]{8,120}$/', (string)$key)) {
    sendJson(['error' => 'product_id, quantity (1..20), and Idempotency-Key (8..120 safe characters) are required'], 400);
}

$pdo = database();
$existing = $pdo->prepare('SELECT * FROM orders WHERE idempotency_key = ?');
$existing->execute([$key]);
$previous = $existing->fetch();
if ($previous) {
    if ($previous['status'] === 'COMPLETED') {
        sendJson(['data' => $previous, 'replayed' => true], 200);
    }
    sendJson(['error' => 'This idempotency key was already used', 'status' => $previous['status']], 409);
}

$insert = $pdo->prepare("INSERT INTO orders (idempotency_key, customer_id, product_id, quantity) VALUES (?, ?, ?, ?)");
$insert->execute([$key, (int)$identity['claims']['sub'], $productId, $quantity]);
$orderId = (int)$pdo->lastInsertId();
$token = $identity['token'];
$inventoryReserved = false;
$paymentCreated = false;
$paymentId = null;
$shipmentId = null;
$total = '0.00';
try {
    $inventory = upstream(
        'inventory-service',
        (getenv('INVENTORY_URL') ?: 'http://inventory-service:8080') . '/reserve',
        'POST',
        ['product_id' => $productId, 'quantity' => $quantity, 'reservation_key' => (string)$key],
        $token,
        $requestId
    );
    $inventoryReserved = true;
    $total = (string)$inventory['total'];
    $payment = upstream(
        'payment-service',
        (getenv('PAYMENT_URL') ?: 'http://payment-service:8080') . '/charge',
        'POST',
        ['order_id' => $orderId, 'idempotency_key' => (string)$key, 'amount' => $total],
        $token,
        $requestId
    );
    $paymentCreated = true;
    $paymentId = (string)$payment['payment_id'];
    $delivery = upstream(
        'delivery-service',
        (getenv('DELIVERY_URL') ?: 'http://delivery-service:8080') . '/ship',
        'POST',
        ['order_id' => $orderId, 'idempotency_key' => (string)$key],
        $token,
        $requestId
    );
    $shipmentId = (string)$delivery['shipment_id'];
    $update = $pdo->prepare("UPDATE orders SET total = ?, status = 'COMPLETED', payment_id = ?, shipment_id = ? WHERE id = ?");
    $update->execute([$total, $paymentId, $shipmentId, $orderId]);
    $query = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
    $query->execute([$orderId]);
    sendJson(['data' => $query->fetch(), 'replayed' => false], 201);
} catch (Throwable $exception) {
    if ($shipmentId !== null) {
        try {
            upstream('delivery-service', (getenv('DELIVERY_URL') ?: 'http://delivery-service:8080') . '/cancel', 'POST', ['shipment_id' => $shipmentId], $token, $requestId);
        } catch (Throwable $compensationError) {
            reportEvent('order-service', 'CRITICAL', 'Delivery compensation failed for order ' . $orderId, $requestId);
        }
    }
    if ($paymentCreated && $paymentId !== null) {
        try {
            upstream('payment-service', (getenv('PAYMENT_URL') ?: 'http://payment-service:8080') . '/refund', 'POST', ['payment_id' => $paymentId], $token, $requestId);
        } catch (Throwable $compensationError) {
            reportEvent('order-service', 'CRITICAL', 'Payment compensation failed for order ' . $orderId, $requestId);
        }
    }
    if ($inventoryReserved) {
        try {
            upstream('inventory-service', (getenv('INVENTORY_URL') ?: 'http://inventory-service:8080') . '/release', 'POST', ['reservation_key' => (string)$key], $token, $requestId);
        } catch (Throwable $compensationError) {
            reportEvent('order-service', 'CRITICAL', 'Inventory compensation failed for order ' . $orderId, $requestId);
        }
    }
    $update = $pdo->prepare("UPDATE orders SET total = ?, status = 'FAILED', failure_reason = ? WHERE id = ?");
    $update->execute([$total, substr($exception->getMessage(), 0, 250), $orderId]);
    reportEvent('order-service', 'ERROR', 'Order ' . $orderId . ' failed: ' . $exception->getMessage(), $requestId);
    sendJson(['error' => 'Order workflow failed', 'order_id' => $orderId, 'request_id' => $requestId], 503);
}

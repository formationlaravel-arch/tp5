<?php
declare(strict_types=1);

require '/app/common/http.php';
$requestId = requestId();
header('X-Request-ID: ' . $requestId);

function deliveryDb(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . (getenv('DELIVERY_DB') ?: '/data/deliveries.sqlite'), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $schema = file_get_contents('/app/init.sql');
        if ($schema === false) {
            throw new RuntimeException('Delivery schema file is missing.');
        }
        $pdo->exec($schema);
        $columns = $pdo->query('PRAGMA table_info(deliveries)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('status', $columns, true)) {
            $pdo->exec("ALTER TABLE deliveries ADD COLUMN status TEXT NOT NULL DEFAULT 'PENDING'");
        }
    }
    return $pdo;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    sendJson(['status' => 'ok', 'service' => 'delivery-service']);
}
if (!in_array($path, ['/ship', '/cancel', '/deliveries', '/status'], true)) {
    sendJson(['error' => 'Not found'], 404);
}
$identity = authorize(getenv('AUTH_URL') ?: 'http://auth-service:8080');
$role = (string)($identity['claims']['role'] ?? '');
$pdo = deliveryDb();
if ($path === '/deliveries' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!in_array($role, ['DELIVERY_MANAGER', 'ADMIN'], true)) {
        sendJson(['error' => 'DELIVERY_MANAGER or ADMIN role required'], 403);
    }
    $rows = $pdo->query('SELECT shipment_id, order_id, state, status, created_at FROM deliveries ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    sendJson(['data' => $rows]);
}
if ($path === '/status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($role, ['DELIVERY_MANAGER', 'ADMIN'], true)) {
        sendJson(['error' => 'DELIVERY_MANAGER or ADMIN role required'], 403);
    }
    $input = readJson();
    $shipmentId = (string)($input['shipment_id'] ?? '');
    $status = (string)($input['status'] ?? '');
    if (!preg_match('/^ship_[a-f0-9]{20}$/', $shipmentId) ||
        !in_array($status, ['PENDING', 'IN_TRANSIT', 'DELIVERED', 'CANCELLED'], true)) {
        sendJson(['error' => 'Valid shipment_id and status are required'], 400);
    }
    $update = $pdo->prepare('UPDATE deliveries SET status = ? WHERE shipment_id = ?');
    $update->execute([$status, $shipmentId]);
    if ($update->rowCount() === 0) {
        $lookup = $pdo->prepare('SELECT 1 FROM deliveries WHERE shipment_id = ?');
        $lookup->execute([$shipmentId]);
        if (!$lookup->fetchColumn()) {
            sendJson(['error' => 'Shipment not found'], 404);
        }
    }
    sendJson(['shipment_id' => $shipmentId, 'status' => $status]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed'], 405);
}
if (!in_array($role, ['CUSTOMER', 'DELIVERY_MANAGER', 'ADMIN'], true)) {
    sendJson(['error' => 'CUSTOMER, DELIVERY_MANAGER or ADMIN role required'], 403);
}
$input = readJson();
if ($path === '/ship') {
    $orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
    $key = (string)($input['idempotency_key'] ?? '');
    if (!$orderId || !preg_match('/^[A-Za-z0-9._-]{8,120}$/', $key)) {
        sendJson(['error' => 'Valid order_id and idempotency_key are required'], 400);
    }
    $query = $pdo->prepare('SELECT shipment_id, state, status FROM deliveries WHERE idempotency_key = ?');
    $query->execute([$key]);
    $existing = $query->fetch();
    if ($existing) {
        if ($existing['state'] !== 'CREATED') {
            sendJson(['error' => 'Idempotency key already used'], 409);
        }
        sendJson(['shipment_id' => $existing['shipment_id'], 'state' => $existing['state'], 'status' => $existing['status']]);
    }
    $shipmentId = 'ship_' . bin2hex(random_bytes(10));
    $insert = $pdo->prepare("INSERT INTO deliveries (shipment_id, idempotency_key, order_id, state, status) VALUES (?, ?, ?, 'CREATED', 'PENDING')");
    $insert->execute([$shipmentId, $key, $orderId]);
    reportEvent('delivery-service', 'INFO', 'Expédition simulée créée ' . $shipmentId, $requestId);
    sendJson(['shipment_id' => $shipmentId, 'state' => 'CREATED', 'status' => 'PENDING'], 201);
}

$shipmentId = (string)($input['shipment_id'] ?? '');
if (!preg_match('/^ship_[a-f0-9]{20}$/', $shipmentId)) {
    sendJson(['error' => 'Valid shipment_id is required'], 400);
}
$cancel = $pdo->prepare("UPDATE deliveries SET state = 'CANCELLED', status = 'CANCELLED' WHERE shipment_id = ? AND state = 'CREATED'");
$cancel->execute([$shipmentId]);
$lookup = $pdo->prepare('SELECT state FROM deliveries WHERE shipment_id = ?');
$lookup->execute([$shipmentId]);
$delivery = $lookup->fetch();
if (!$delivery) {
    sendJson(['error' => 'Shipment not found'], 404);
}
sendJson(['shipment_id' => $shipmentId, 'state' => $delivery['state'], 'status' => $delivery['state'] === 'CANCELLED' ? 'CANCELLED' : 'PENDING']);

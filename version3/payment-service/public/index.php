<?php
declare(strict_types=1);

require '/app/common/http.php';
$requestId = requestId();
header('X-Request-ID: ' . $requestId);

function paymentDb(): PDO
{
    static $pdo = null;
    if (!$pdo) {
        $pdo = new PDO('sqlite:' . (getenv('PAYMENT_DB') ?: '/data/payments.sqlite'), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $schema = file_get_contents('/app/init.sql');
        if ($schema === false) {
            throw new RuntimeException('Payment schema file is missing.');
        }
        $pdo->exec($schema);
    }
    return $pdo;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/health') {
    sendJson(['status' => 'ok', 'service' => 'payment-service']);
}
if (!in_array($path, ['/charge', '/refund', '/payments', '/record'], true)) {
    sendJson(['error' => 'Not found'], 404);
}
$identity = authorize(getenv('AUTH_URL') ?: 'http://auth-service:8080');
$role = (string)($identity['claims']['role'] ?? '');
if (!in_array($role, ['CUSTOMER', 'FINANCE_OFFICER', 'ADMIN'], true)) {
    sendJson(['error' => 'CUSTOMER, FINANCE_OFFICER or ADMIN role required'], 403);
}
$pdo = paymentDb();

if ($path === '/payments' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!in_array($role, ['FINANCE_OFFICER', 'ADMIN'], true)) {
        sendJson(['error' => 'FINANCE_OFFICER or ADMIN role required'], 403);
    }
    $payments = $pdo->query(
        "SELECT payment_id, order_id, amount,
                CASE state WHEN 'CHARGED' THEN 'PAID' ELSE 'REFUNDED' END AS status,
                created_at
         FROM payments
         UNION ALL
         SELECT record_id AS payment_id, order_id, amount, status, created_at
         FROM finance_records
         ORDER BY created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    sendJson(['data' => $payments]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed'], 405);
}
$input = readJson();

if ($path === '/record') {
    if (!in_array($role, ['FINANCE_OFFICER', 'ADMIN'], true)) {
        sendJson(['error' => 'FINANCE_OFFICER or ADMIN role required'], 403);
    }
    $orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
    $amount = $input['amount'] ?? null;
    $status = (string)($input['status'] ?? '');
    if (!$orderId || (!is_string($amount) && !is_numeric($amount)) ||
        !preg_match('/^\d{1,10}\.\d{2}$/', (string)$amount) || (float)$amount <= 0 ||
        !in_array($status, ['PENDING', 'PAID', 'REFUSED'], true)) {
        sendJson(['error' => 'Valid order_id, positive amount and status are required'], 400);
    }
    $recordId = 'rec_' . bin2hex(random_bytes(10));
    $statement = $pdo->prepare('INSERT INTO finance_records (record_id, order_id, amount, status, processed_by) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$recordId, $orderId, number_format((float)$amount, 2, '.', ''), $status, (int)$identity['claims']['sub']]);
    reportEvent('payment-service', 'INFO', 'Finance payment record created ' . $recordId, $requestId);
    sendJson(['data' => [
        'payment_id' => $recordId,
        'order_id' => $orderId,
        'amount' => number_format((float)$amount, 2, '.', ''),
        'status' => $status
    ]], 201);
}

if ($path === '/charge') {
    $orderId = filter_var($input['order_id'] ?? null, FILTER_VALIDATE_INT);
    $key = (string)($input['idempotency_key'] ?? '');
    $amount = $input['amount'] ?? null;
    if (!$orderId || !preg_match('/^[A-Za-z0-9._-]{8,120}$/', $key) ||
        !is_string($amount) && !is_numeric($amount) ||
        !preg_match('/^\d{1,10}\.\d{2}$/', (string)$amount) || (float)$amount <= 0) {
        sendJson(['error' => 'Valid order_id, idempotency_key and positive amount are required'], 400);
    }
    $query = $pdo->prepare('SELECT payment_id, amount, state FROM payments WHERE idempotency_key = ?');
    $query->execute([$key]);
    $existing = $query->fetch();
    if ($existing) {
        if ($existing['state'] !== 'CHARGED' || $existing['amount'] !== $amount) {
            sendJson(['error' => 'Idempotency key already used'], 409);
        }
        sendJson(['payment_id' => $existing['payment_id'], 'state' => $existing['state'], 'amount' => $existing['amount']]);
    }
    $paymentId = 'pay_' . bin2hex(random_bytes(10));
    $insert = $pdo->prepare("INSERT INTO payments (payment_id, idempotency_key, order_id, amount, state) VALUES (?, ?, ?, ?, 'CHARGED')");
    $insert->execute([$paymentId, $key, $orderId, number_format((float)$amount, 2, '.', '')]);
    reportEvent('payment-service', 'INFO', 'Simulation de paiement autorisée pour ' . $paymentId, $requestId);
    sendJson(['payment_id' => $paymentId, 'state' => 'CHARGED', 'amount' => $amount], 201);
}

$paymentId = (string)($input['payment_id'] ?? '');
if (!preg_match('/^pay_[a-f0-9]{20}$/', $paymentId)) {
    sendJson(['error' => 'Valid payment_id is required'], 400);
}
$update = $pdo->prepare("UPDATE payments SET state = 'REFUNDED' WHERE payment_id = ? AND state = 'CHARGED'");
$update->execute([$paymentId]);
$lookup = $pdo->prepare('SELECT state FROM payments WHERE payment_id = ?');
$lookup->execute([$paymentId]);
$payment = $lookup->fetch();
if (!$payment) {
    sendJson(['error' => 'Payment not found'], 404);
}
sendJson(['payment_id' => $paymentId, 'state' => $payment['state']]);

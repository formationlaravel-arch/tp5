<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

$dbHost = getenv('V1_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('V1_DB_NAME') ?: 'mpin_spaghetti_db';
$dbUser = getenv('V1_DB_USER') ?: 'root';
$dbPass = getenv('V1_DB_PASS') ?: '';
$auditFile = __DIR__ . DIRECTORY_SEPARATOR . 'security_audit.log';

function auditEvent(string $event, ?int $userId = null): void
{
    global $auditFile;
    $entry = json_encode([
        'time' => date(DATE_ATOM),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_id' => $userId,
        'event' => $event
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    file_put_contents($auditFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $statusColumn = $pdo->query("SHOW COLUMNS FROM system_orders LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if (!$statusColumn) {
        $pdo->exec("ALTER TABLE system_orders ADD COLUMN status ENUM('PENDING','PAID','SHIPPED','CANCELLED') NOT NULL DEFAULT 'PENDING'");
    }
} catch (PDOException $exception) {
    http_response_code(500);
    error_log((string)$exception);
    exit('Base indisponible ou migration nécessaire. Vérifiez database_v1.sql, les droits MySQL et la configuration WAMP.');
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$message = '';

$roleHierarchy = [
    'ADMIN' => ['ADMIN','INVENTORY_MANAGER','ORDER_MANAGER','DELIVERY_MANAGER','FINANCE_OFFICER','CUSTOMER'],
    'INVENTORY_MANAGER' => ['INVENTORY_MANAGER'],
    'ORDER_MANAGER' => ['ORDER_MANAGER'],
    'DELIVERY_MANAGER' => ['DELIVERY_MANAGER'],
    'FINANCE_OFFICER' => ['FINANCE_OFFICER'],
    'CUSTOMER' => ['CUSTOMER']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $statement = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
    $statement->execute([trim((string)($_POST['username'] ?? ''))]);
    $user = $statement->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        auditEvent('login_success', (int)$user['id']);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    auditEvent('login_failure');
    $message = 'Identifiants invalides.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    auditEvent('logout', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    $_SESSION = [];
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'checkout') {
    if ((!isset($_SESSION['user_id'])) || (($_SESSION['role'] ?? '') !== 'CUSTOMER')) {
        http_response_code(403);
        $message = 'Seul un CUSTOMER peut acheter.';
        auditEvent('rbac_denied_checkout', isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
    } else {
        if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            $message = 'Jeton de formulaire invalide.';
        } else {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$itemId || !$quantity || $quantity < 1 || $quantity > 20) {
                $message = 'Article ou quantité invalide (1 à 20).';
            } else {
                try {
                    $pdo->beginTransaction();
                    $lock = $pdo->prepare('SELECT price, stock_qty FROM global_store WHERE item_id = ? FOR UPDATE');
                    $lock->execute([$itemId]);
                    $item = $lock->fetch(PDO::FETCH_ASSOC);
                    if (!$item || (int)$item['stock_qty'] < $quantity) {
                        throw new RuntimeException('Article indisponible ou stock insuffisant.');
                    }
                    $total = (float)$item['price'] * $quantity;
                    $decrease = $pdo->prepare('UPDATE global_store SET stock_qty = stock_qty - ? WHERE item_id = ?');
                    $decrease->execute([$quantity, $itemId]);
                    $order = $pdo->prepare('INSERT INTO system_orders (user_id, item_id, quantity, total, status) VALUES (?, ?, ?, ?, "PENDING")');
                    $order->execute([(int)$_SESSION['user_id'], $itemId, $quantity, number_format($total, 2, '.', '')]);
                    $orderId = (int)$pdo->lastInsertId();
                    $payment = $pdo->prepare('INSERT INTO payment_transactions (order_id, amount, status, processed_by) VALUES (?, ?, "PENDING", ?)');
                    $payment->execute([$orderId, number_format($total, 2, '.', ''), (int)$_SESSION['user_id']]);
                    $pdo->commit();
                    auditEvent('checkout_success order_id=' . $orderId, (int)$_SESSION['user_id']);
                    $message = 'Commande #' . $orderId . ' créée; total ' . number_format($total, 2, ',', ' ') . ' €.';
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    auditEvent('checkout_failure ' . get_class($exception), (int)$_SESSION['user_id']);
                    $message = $exception instanceof RuntimeException ? $exception->getMessage() : 'Échec de la commande.';
                }
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restock') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'INVENTORY_MANAGER' || $role === 'ADMIN') {
        $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($_POST['qty'] ?? null, FILTER_VALIDATE_INT);
        if ($itemId && $qty && $qty > 0) {
            $stmt = $pdo->prepare('UPDATE global_store SET stock_qty = stock_qty + ? WHERE item_id = ?');
            $stmt->execute([$qty, $itemId]);
            $message = 'Réapprovisionnement enregistré.';
            auditEvent('restock item_id=' . $itemId, (int)($_SESSION['user_id'] ?? 0));
        }
    } else {
        $message = 'Rôle insuffisant pour gérer le stock.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_order_status') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'ORDER_MANAGER' || $role === 'ADMIN') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $_POST['status'] ?? 'PENDING';
        if ($orderId && in_array($status, ['PENDING','PAID','SHIPPED','CANCELLED'], true)) {
            $stmt = $pdo->prepare('UPDATE system_orders SET status = ? WHERE order_id = ?');
            $stmt->execute([$status, $orderId]);
            $message = 'Statut de commande mis à jour.';
            auditEvent('order_status_update order_id=' . $orderId, (int)($_SESSION['user_id'] ?? 0));
        }
    } else {
        $message = 'Rôle insuffisant pour gérer les commandes.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispatch_delivery') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'DELIVERY_MANAGER' || $role === 'ADMIN') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        if ($orderId) {
            $tracking = 'TRK-' . strtoupper(bin2hex(random_bytes(6)));
            $stmt = $pdo->prepare('INSERT INTO order_deliveries (order_id, assigned_to, status, tracking_code) VALUES (?, ?, "IN_TRANSIT", ?)');
            $stmt->execute([$orderId, (int)($_SESSION['user_id'] ?? 0), $tracking]);
            $message = 'Livraison créée: ' . $tracking;
            auditEvent('delivery_dispatch order_id=' . $orderId, (int)($_SESSION['user_id'] ?? 0));
        }
    } else {
        $message = 'Rôle insuffisant pour gérer les livraisons.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_delivery_status') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'DELIVERY_MANAGER' || $role === 'ADMIN') {
        $deliveryId = filter_var($_POST['delivery_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($_POST['status'] ?? '');
        if ($deliveryId && in_array($status, ['PENDING', 'IN_TRANSIT', 'DELIVERED'], true)) {
            $stmt = $pdo->prepare('UPDATE order_deliveries SET status = ? WHERE delivery_id = ?');
            $stmt->execute([$status, $deliveryId]);
            if ($stmt->rowCount() > 0) {
                $message = 'Statut de livraison mis à jour.';
                auditEvent('delivery_status_update delivery_id=' . $deliveryId, (int)($_SESSION['user_id'] ?? 0));
            } else {
                $exists = $pdo->prepare('SELECT 1 FROM order_deliveries WHERE delivery_id = ?');
                $exists->execute([$deliveryId]);
                $message = $exists->fetchColumn() ? 'Le statut de livraison est inchangé.' : 'Livraison introuvable.';
            }
        } else {
            $message = 'Livraison ou statut invalide.';
        }
    } else {
        $message = 'Rôle insuffisant pour gérer les livraisons.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'FINANCE_OFFICER' || $role === 'ADMIN') {
        $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
        $status = $_POST['status'] ?? 'PAID';
        if ($orderId && $amount && in_array($status, ['PAID','REFUSED','PENDING'], true)) {
            $stmt = $pdo->prepare('INSERT INTO payment_transactions (order_id, amount, status, processed_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$orderId, number_format($amount, 2, '.', ''), $status, (int)($_SESSION['user_id'] ?? 0)]);
            $message = 'Paiement enregistré pour la commande #' . $orderId . '.';
            auditEvent('payment_record order_id=' . $orderId, (int)($_SESSION['user_id'] ?? 0));
        }
    } else {
        $message = 'Rôle insuffisant pour gérer les paiements.';
    }
}

$isLoggedIn = isset($_SESSION['user_id']);
$role = $isLoggedIn ? (string)$_SESSION['role'] : '';

$items = $pdo->query('SELECT item_id, item_name, price, stock_qty FROM global_store ORDER BY item_id')->fetchAll(PDO::FETCH_ASSOC);
$orders = $pdo->query('SELECT o.order_id, u.username, s.item_name, o.quantity, o.total, o.status, o.created_at FROM system_orders o JOIN users u ON u.id=o.user_id JOIN global_store s ON s.item_id=o.item_id ORDER BY o.order_id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
$deliveries = $pdo->query('SELECT d.delivery_id, d.order_id, d.tracking_code, d.status, u.username AS assigned_name FROM order_deliveries d JOIN users u ON u.id=d.assigned_to ORDER BY d.delivery_id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
$payments = $pdo->query('SELECT p.payment_id, p.order_id, p.amount, p.status, u.username AS finance_name FROM payment_transactions p JOIN users u ON u.id=p.processed_by ORDER BY p.payment_id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);

$allowedDashboard = in_array($role, ['ADMIN','INVENTORY_MANAGER','ORDER_MANAGER','DELIVERY_MANAGER','FINANCE_OFFICER','CUSTOMER'], true);
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monolithe spaghetti v1</title>
<style>
body{font:16px Arial,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#222}
section{border:1px solid #bbb;padding:1rem;margin:1rem 0;border-radius:8px}
label{display:block;margin:.5rem 0}input,select,button{padding:.45rem;margin:.15rem 0}table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:.45rem;text-align:left}.notice{background:#eef;padding:.8rem}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
</style>
</head>
<body>
<h1>Version 1 — Monolithe spaghetti</h1>
<p>Les fonctions de produit, commandes, livraisons et paiements sont intégrées dans un seul monolithe, avec authentification native et contrôle RBAC artisanal.</p>
<?php if ($message !== ''): ?><p class="notice"><?= h($message) ?></p><?php endif; ?>
<?php if (!$isLoggedIn): ?>
<section>
<h2>Connexion</h2>
<form method="post">
<input type="hidden" name="action" value="login">
<label>Utilisateur <input name="username" required autocomplete="username"></label>
<label>Mot de passe <input name="password" type="password" required autocomplete="current-password"></label>
<button type="submit">Se connecter</button>
</form>
<p>Démo : <code>admin / Admin123!</code> ; <code>inventory / Inventory123!</code> ; <code>orders / Order123!</code> ; <code>delivery / Delivery123!</code> ; <code>finance / Finance123!</code> ; <code>customer / Customer123!</code></p>
</section>
<?php else: ?>
<p>Connecté : <strong><?= h((string)$_SESSION['username']) ?></strong> (<?= h($role) ?>)</p>
<form method="post"><input type="hidden" name="action" value="logout"><button>Déconnexion</button></form>
<?php if ($role === 'ADMIN' || $role === 'INVENTORY_MANAGER'): ?>
<section>
<h2>Stock / Inventaire</h2>
<form method="post">
<input type="hidden" name="action" value="restock">
<label>Article <select name="item_id"><?php foreach ($items as $item): ?><option value="<?= (int)$item['item_id'] ?>"><?= h($item['item_name']) ?> (Stock: <?= (int)$item['stock_qty'] ?>)</option><?php endforeach; ?></select></label>
<label>Quantité à rajouter <input type="number" min="1" max="1000" name="qty" value="10" required></label>
<button type="submit">Réapprovisionner</button>
</form>
</section>
<?php endif; ?>
<?php if ($role === 'ADMIN' || $role === 'ORDER_MANAGER'): ?>
<section>
<h2>Gestion des commandes</h2>
<form method="post"><input type="hidden" name="action" value="update_order_status">
<label>Commande <select name="order_id"><?php foreach ($orders as $order): ?><option value="<?= (int)$order['order_id'] ?>"><?= (int)$order['order_id'] ?> - <?= h($order['item_name']) ?></option><?php endforeach; ?></select></label>
<label>Statut <select name="status"><option value="PENDING">PENDING</option><option value="PAID">PAID</option><option value="SHIPPED">SHIPPED</option><option value="CANCELLED">CANCELLED</option></select></label>
<button type="submit">Mettre à jour</button>
</form>
</section>
<?php endif; ?>
<?php if ($role === 'ADMIN' || $role === 'DELIVERY_MANAGER'): ?>
<section>
<h2>Livraisons</h2>
<form method="post"><input type="hidden" name="action" value="dispatch_delivery">
<label>Commande <select name="order_id"><?php foreach ($orders as $order): ?><option value="<?= (int)$order['order_id'] ?>"><?= (int)$order['order_id'] ?> - <?= h($order['item_name']) ?></option><?php endforeach; ?></select></label>
<button type="submit">Créer une livraison</button>
</form>
<form method="post"><input type="hidden" name="action" value="update_delivery_status">
<label>Livraison <select name="delivery_id"><?php foreach ($deliveries as $delivery): ?><option value="<?= (int)$delivery['delivery_id'] ?>">#<?= (int)$delivery['delivery_id'] ?> — <?= h($delivery['tracking_code']) ?></option><?php endforeach; ?></select></label>
<label>Statut <select name="status"><option value="PENDING">PENDING</option><option value="IN_TRANSIT">IN_TRANSIT</option><option value="DELIVERED">DELIVERED</option></select></label>
<button type="submit">Mettre à jour la livraison</button>
</form>
</section>
<?php endif; ?>
<?php if ($role === 'ADMIN' || $role === 'FINANCE_OFFICER'): ?>
<section>
<h2>Paiements</h2>
<form method="post"><input type="hidden" name="action" value="record_payment">
<label>Commande <select name="order_id"><?php foreach ($orders as $order): ?><option value="<?= (int)$order['order_id'] ?>"><?= (int)$order['order_id'] ?> - <?= h($order['item_name']) ?></option><?php endforeach; ?></select></label>
<label>Montant <input type="number" step="0.01" name="amount" value="50.00" required></label>
<label>Statut <select name="status"><option value="PAID">PAID</option><option value="REFUSED">REFUSED</option><option value="PENDING">PENDING</option></select></label>
<button type="submit">Enregistrer</button>
</form>
</section>
<?php endif; ?>
<?php if ($role === 'CUSTOMER'): ?>
<section>
<h2>Achat</h2>
<form method="post">
<input type="hidden" name="action" value="checkout">
<input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
<label>Article <select name="item_id"><?php foreach ($items as $item): ?><option value="<?= (int)$item['item_id'] ?>"><?= h($item['item_name']) ?> — <?= number_format((float)$item['price'], 2, ',', ' ') ?> € (stock <?= (int)$item['stock_qty'] ?>)</option><?php endforeach; ?></select></label>
<label>Quantité <input name="quantity" type="number" min="1" max="20" value="1" required></label>
<button type="submit">Commander</button>
</form>
</section>
<?php endif; ?>
<section>
<h2>Vue d'ensemble</h2>
<div class="grid">
  <div><h3>Catalogue</h3><table><tr><th>Produit</th><th>Prix</th><th>Stock</th></tr><?php foreach ($items as $item): ?><tr><td><?= h($item['item_name']) ?></td><td><?= number_format((float)$item['price'], 2, ',', ' ') ?> €</td><td><?= (int)$item['stock_qty'] ?></td></tr><?php endforeach; ?></table></div>
  <div><h3>Commandes</h3><table><tr><th>#</th><th>Produit</th><th>Statut</th></tr><?php foreach ($orders as $order): ?><tr><td><?= (int)$order['order_id'] ?></td><td><?= h($order['item_name']) ?></td><td><?= h((string)$order['status']) ?></td></tr><?php endforeach; ?></table></div>
  <div><h3>Livraisons</h3><table><tr><th>#</th><th>Tracking</th><th>Statut</th></tr><?php foreach ($deliveries as $delivery): ?><tr><td><?= (int)$delivery['delivery_id'] ?></td><td><?= h($delivery['tracking_code']) ?></td><td><?= h((string)$delivery['status']) ?></td></tr><?php endforeach; ?></table></div>
  <div><h3>Paiements</h3><table><tr><th>#</th><th>Montant</th><th>Statut</th></tr><?php foreach ($payments as $payment): ?><tr><td><?= (int)$payment['payment_id'] ?></td><td><?= number_format((float)$payment['amount'], 2, ',', ' ') ?> €</td><td><?= h((string)$payment['status']) ?></td></tr><?php endforeach; ?></table></div>
</div>
</section>
<?php endif; ?>
</body>
</html>

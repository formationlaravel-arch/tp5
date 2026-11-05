<?php
declare(strict_types=1);

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();

$apiBase = rtrim(getenv('V2_API_BASE_URL') ?: 'http://localhost/lab_mpin/version2/public/index.php', '/');

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function apiRequest(string $method, string $path, ?array $payload = null): array
{
    global $apiBase;
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if (!empty($_SESSION['api_token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['api_token'];
    }

    $options = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ];
    $body = @file_get_contents($apiBase . $path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    if ($body === false || $status === 0) {
        throw new RuntimeException('API inaccessible. Vérifiez WAMP et V2_API_BASE_URL.');
    }
    $result = json_decode($body, true);
    if (!is_array($result)) {
        throw new RuntimeException('Réponse JSON invalide reçue de l’API.');
    }
    if ($status < 200 || $status >= 300) {
        if ($status === 401 && isset($_SESSION['api_token'])) {
            unset($_SESSION['api_token'], $_SESSION['identity']);
        }
        $error = $result['error'] ?? $result['message'] ?? $result['detail'] ?? 'Erreur API HTTP ' . $status;
        throw new RuntimeException(is_string($error) ? $error : 'Erreur API HTTP ' . $status);
    }
    return $result;
}

function requireCsrf(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        throw new RuntimeException('Formulaire expiré. Rechargez la page et réessayez.');
    }
}

if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'login') {
            $login = apiRequest('POST', '/api/v1/auth/login', [
                'username' => trim((string)($_POST['username'] ?? '')),
                'password' => (string)($_POST['password'] ?? '')
            ]);
            if (!isset($login['access_token']) || !is_string($login['access_token'])) {
                throw new RuntimeException('L’API n’a pas retourné de JWT valide.');
            }
            session_regenerate_id(true);
            $_SESSION['api_token'] = $login['access_token'];
            $_SESSION['identity'] = apiRequest('GET', '/api/v1/auth/me')['data'] ?? null;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }

        switch ($action) {
            case 'order':
                apiRequest('POST', '/api/v1/orders', [
                    'product_id' => (int)($_POST['product_id'] ?? 0),
                    'quantity' => (int)($_POST['quantity'] ?? 0)
                ]);
                $message = 'Commande créée et stock réservé.';
                break;
            case 'restock':
                apiRequest('POST', '/api/v1/products/restock', [
                    'product_id' => (int)($_POST['product_id'] ?? 0),
                    'quantity' => (int)($_POST['quantity'] ?? 0)
                ]);
                $message = 'Réapprovisionnement enregistré.';
                break;
            case 'order_status':
                apiRequest('POST', '/api/v1/orders/status', [
                    'order_id' => (int)($_POST['order_id'] ?? 0),
                    'status' => (string)($_POST['status'] ?? '')
                ]);
                $message = 'Statut de commande mis à jour.';
                break;
            case 'create_delivery':
                apiRequest('POST', '/api/v1/deliveries', ['order_id' => (int)($_POST['order_id'] ?? 0)]);
                $message = 'Livraison créée.';
                break;
            case 'delivery_status':
                apiRequest('POST', '/api/v1/deliveries/status', [
                    'delivery_id' => (int)($_POST['delivery_id'] ?? 0),
                    'status' => (string)($_POST['status'] ?? '')
                ]);
                $message = 'Statut de livraison mis à jour.';
                break;
            case 'payment':
                apiRequest('POST', '/api/v1/payments', [
                    'order_id' => (int)($_POST['order_id'] ?? 0),
                    'amount' => (string)($_POST['amount'] ?? ''),
                    'status' => (string)($_POST['status'] ?? 'PAID')
                ]);
                $message = 'Paiement enregistré.';
                break;
            default:
                throw new RuntimeException('Action inconnue.');
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

$identity = $_SESSION['identity'] ?? null;
$role = is_array($identity) ? (string)($identity['role'] ?? '') : '';
$products = [];
$orders = [];
$deliveries = [];
$payments = [];
$pageError = '';

if ($identity !== null) {
    try {
        $products = apiRequest('GET', '/api/v1/products')['data'] ?? [];
        if (in_array($role, ['CUSTOMER', 'ORDER_MANAGER', 'DELIVERY_MANAGER', 'FINANCE_OFFICER', 'ADMIN'], true)) {
            $orders = apiRequest('GET', '/api/v1/orders')['data'] ?? [];
        }
        if (in_array($role, ['DELIVERY_MANAGER', 'ADMIN'], true)) {
            $deliveries = apiRequest('GET', '/api/v1/deliveries')['data'] ?? [];
        }
        if (in_array($role, ['FINANCE_OFFICER', 'ADMIN'], true)) {
            $payments = apiRequest('GET', '/api/v1/payments')['data'] ?? [];
        }
    } catch (Throwable $exception) {
        $pageError = $exception->getMessage();
    }
}

$canManageOrders = in_array($role, ['ORDER_MANAGER', 'ADMIN'], true);
$canManageInventory = in_array($role, ['INVENTORY_MANAGER', 'ADMIN'], true);
$canManageDeliveries = in_array($role, ['DELIVERY_MANAGER', 'ADMIN'], true);
$canManagePayments = in_array($role, ['FINANCE_OFFICER', 'ADMIN'], true);
$csrf = h($_SESSION['csrf']);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MPIN E-Commerce — Client API v2</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
<header class="bg-slate-900 text-white">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-5">
        <div>
            <p class="text-sm font-semibold uppercase tracking-widest text-cyan-300">Étude de cas MPIN</p>
            <h1 class="text-2xl font-bold">E-Commerce — Client API REST</h1>
        </div>
        <?php if ($identity !== null): ?>
            <div class="flex items-center gap-4 text-sm">
                <span><?= h((string)$identity['username']) ?> · <?= h($role) ?></span>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="rounded-lg bg-slate-700 px-4 py-2 hover:bg-slate-600">Déconnexion</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
</header>

<main class="mx-auto max-w-7xl space-y-6 px-6 py-8">
    <?php if ($message !== ''): ?>
        <div class="rounded-xl border px-4 py-3 <?= $messageType === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($pageError !== ''): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-800"><?= h($pageError) ?></div>
    <?php endif; ?>

    <?php if ($identity === null): ?>
        <section class="mx-auto max-w-md rounded-2xl bg-white p-8 shadow-sm">
            <h2 class="text-xl font-bold">Connexion sécurisée</h2>
            <p class="mt-2 text-sm text-slate-600">Les identifiants sont échangés contre un JWT par l’API. Le client transmet ensuite ce jeton dans l’en-tête Authorization.</p>
            <form method="post" class="mt-6 space-y-4">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="login">
                <label class="block text-sm font-medium">Identifiant
                    <input name="username" required autocomplete="username" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <label class="block text-sm font-medium">Mot de passe
                    <input name="password" type="password" required autocomplete="current-password" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <button class="w-full rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Se connecter</button>
            </form>
            <p class="mt-5 text-xs leading-5 text-slate-500">Comptes de démonstration : customer/Customer123!, inventory/Inventory123!, orders/Order123!, delivery/Delivery123!, finance/Finance123! et admin/Admin123!.</p>
        </section>
    <?php else: ?>
        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold">Espace <?= h($role) ?></h2>
                    <p class="mt-1 text-sm text-slate-600">Interface client unique : les opérations sont exécutées par l’API MVC et protégées par JWT/RBAC.</p>
                </div>
                <a class="text-sm font-semibold text-cyan-800 underline" href="../openapi.yaml">Contrat OpenAPI</a>
            </div>
        </section>

        <?php if ($role === 'CUSTOMER'): ?>
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold">Passer une commande</h2>
                <form method="post" class="mt-4 grid gap-4 sm:grid-cols-4">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="order">
                    <label class="text-sm font-medium sm:col-span-2">Produit
                        <select name="product_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int)$product['id'] ?>"><?= h((string)$product['name']) ?> — <?= h((string)$product['price']) ?> € (stock <?= (int)$product['stock'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-sm font-medium">Quantité
                        <input type="number" name="quantity" min="1" max="20" value="1" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <button class="self-end rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Commander</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canManageInventory): ?>
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold">Gestion du stock</h2>
                <form method="post" class="mt-4 grid gap-4 sm:grid-cols-4">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="restock">
                    <label class="text-sm font-medium sm:col-span-2">Produit
                        <select name="product_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int)$product['id'] ?>"><?= h((string)$product['name']) ?> (stock <?= (int)$product['stock'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-sm font-medium">Quantité à ajouter
                        <input type="number" name="quantity" min="1" max="1000" value="10" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <button class="self-end rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Réapprovisionner</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canManageOrders): ?>
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold">Gestion des commandes</h2>
                <form method="post" class="mt-4 flex flex-wrap items-end gap-4">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="order_status">
                    <label class="text-sm font-medium">Commande
                        <select name="order_id" required class="mt-1 block rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($orders as $order): ?><option value="<?= (int)$order['id'] ?>">#<?= (int)$order['id'] ?> — <?= h((string)$order['status']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-sm font-medium">Statut
                        <select name="status" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach (['PENDING', 'PAID', 'SHIPPED', 'CANCELLED'] as $status): ?><option><?= $status ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Mettre à jour</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canManageDeliveries): ?>
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold">Gestion des livraisons</h2>
                <form method="post" class="mt-4 flex flex-wrap items-end gap-4">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="create_delivery">
                    <label class="text-sm font-medium">Commande à expédier
                        <select name="order_id" required class="mt-1 block rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($orders as $order): ?><option value="<?= (int)$order['id'] ?>">#<?= (int)$order['id'] ?> — <?= h((string)$order['status']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Créer une livraison</button>
                </form>
                <form method="post" class="mt-4 flex flex-wrap items-end gap-4">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delivery_status">
                    <label class="text-sm font-medium">Livraison
                        <select name="delivery_id" required class="mt-1 block rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($deliveries as $delivery): ?><option value="<?= (int)$delivery['id'] ?>">#<?= (int)$delivery['id'] ?> — <?= h((string)$delivery['tracking_code']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-sm font-medium">Statut
                        <select name="status" class="mt-1 block rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach (['PENDING', 'IN_TRANSIT', 'DELIVERED'] as $status): ?><option><?= $status ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Mettre à jour</button>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canManagePayments): ?>
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="text-lg font-bold">Suivi des paiements</h2>
                <form method="post" class="mt-4 grid gap-4 sm:grid-cols-4">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="payment">
                    <label class="text-sm font-medium">Commande
                        <select name="order_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach ($orders as $order): ?><option value="<?= (int)$order['id'] ?>">#<?= (int)$order['id'] ?> — <?= h((string)$order['total']) ?> €</option><?php endforeach; ?>
                        </select>
                    </label>
                    <label class="text-sm font-medium">Montant
                        <input type="number" name="amount" min="0.01" step="0.01" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    </label>
                    <label class="text-sm font-medium">Statut
                        <select name="status" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                            <?php foreach (['PAID', 'PENDING', 'REFUSED'] as $status): ?><option><?= $status ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <button class="self-end rounded-lg bg-cyan-700 px-4 py-2 font-semibold text-white hover:bg-cyan-800">Enregistrer le paiement</button>
                </form>
            </section>
        <?php endif; ?>

        <section class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="mb-4 text-lg font-bold">Catalogue</h2>
                <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                    <thead class="border-b text-slate-500"><tr><th class="py-2">Produit</th><th>Prix</th><th>Stock</th></tr></thead>
                    <tbody><?php foreach ($products as $product): ?><tr class="border-b last:border-0"><td class="py-2"><?= h((string)$product['name']) ?></td><td><?= h((string)$product['price']) ?> €</td><td><?= (int)$product['stock'] ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            </div>
            <?php if (in_array($role, ['CUSTOMER', 'ORDER_MANAGER', 'DELIVERY_MANAGER', 'FINANCE_OFFICER', 'ADMIN'], true)): ?>
                <div class="rounded-2xl bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-bold"><?= $role === 'CUSTOMER' ? 'Mes commandes' : 'Commandes' ?></h2>
                    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                        <thead class="border-b text-slate-500"><tr><th class="py-2">#</th><th>Produit</th><th>Qté</th><th>Total</th><th>Statut</th></tr></thead>
                        <tbody><?php foreach ($orders as $order): ?><tr class="border-b last:border-0"><td class="py-2"><?= (int)$order['id'] ?></td><td><?= h((string)($order['product_name'] ?? ('Produit #' . $order['product_id']))) ?></td><td><?= (int)$order['quantity'] ?></td><td><?= h((string)$order['total']) ?> €</td><td><?= h((string)$order['status']) ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                </div>
            <?php endif; ?>
            <?php if ($canManageDeliveries): ?>
                <div class="rounded-2xl bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-bold">Livraisons</h2>
                    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                        <thead class="border-b text-slate-500"><tr><th class="py-2">Commande</th><th>Suivi</th><th>Statut</th></tr></thead>
                        <tbody><?php foreach ($deliveries as $delivery): ?><tr class="border-b last:border-0"><td class="py-2">#<?= (int)$delivery['order_id'] ?></td><td><?= h((string)$delivery['tracking_code']) ?></td><td><?= h((string)$delivery['status']) ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                </div>
            <?php endif; ?>
            <?php if ($canManagePayments): ?>
                <div class="rounded-2xl bg-white p-6 shadow-sm">
                    <h2 class="mb-4 text-lg font-bold">Paiements</h2>
                    <div class="overflow-x-auto"><table class="w-full text-left text-sm">
                        <thead class="border-b text-slate-500"><tr><th class="py-2">Commande</th><th>Montant</th><th>Statut</th></tr></thead>
                        <tbody><?php foreach ($payments as $payment): ?><tr class="border-b last:border-0"><td class="py-2">#<?= (int)$payment['order_id'] ?></td><td><?= h((string)$payment['amount']) ?> €</td><td><?= h((string)$payment['status']) ?></td></tr><?php endforeach; ?></tbody>
                    </table></div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</body>
</html>

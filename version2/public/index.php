<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DeliveryController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\ProductController;
use App\Core\Response;
use App\Core\Router;

require dirname(__DIR__) . '/Core/Database.php';
require dirname(__DIR__) . '/Core/Response.php';
require dirname(__DIR__) . '/Core/Jwt.php';
require dirname(__DIR__) . '/Core/Auth.php';
require dirname(__DIR__) . '/Core/Router.php';
require dirname(__DIR__) . '/Models/User.php';
require dirname(__DIR__) . '/Models/Product.php';
require dirname(__DIR__) . '/Models/Order.php';
require dirname(__DIR__) . '/Models/Delivery.php';
require dirname(__DIR__) . '/Models/Payment.php';
require dirname(__DIR__) . '/Controllers/AuthController.php';
require dirname(__DIR__) . '/Controllers/ProductController.php';
require dirname(__DIR__) . '/Controllers/OrderController.php';
require dirname(__DIR__) . '/Controllers/DeliveryController.php';
require dirname(__DIR__) . '/Controllers/PaymentController.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$router = new Router();
$router->add('GET', '/health', static fn() => Response::json(['status' => 'ok']));
$router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
$router->add('GET', '/api/v1/auth/me', [AuthController::class, 'me']);
$router->add('GET', '/api/v1/products', [ProductController::class, 'index']);
$router->add('POST', '/api/v1/products/restock', [ProductController::class, 'restock']);
$router->add('POST', '/api/v1/orders', [OrderController::class, 'create']);
$router->add('GET', '/api/v1/orders', [OrderController::class, 'index']);
$router->add('POST', '/api/v1/orders/status', [OrderController::class, 'updateStatus']);
$router->add('POST', '/api/v1/deliveries', [DeliveryController::class, 'create']);
$router->add('GET', '/api/v1/deliveries', [DeliveryController::class, 'index']);
$router->add('POST', '/api/v1/deliveries/status', [DeliveryController::class, 'updateStatus']);
$router->add('POST', '/api/v1/payments', [PaymentController::class, 'record']);
$router->add('GET', '/api/v1/payments', [PaymentController::class, 'index']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
if ($scriptDir !== '' && $scriptDir !== '.' && str_starts_with($path, $scriptDir . '/')) {
    $path = substr($path, strlen($scriptDir));
}
if (str_starts_with($path, '/index.php/')) {
    $path = substr($path, strlen('/index.php'));
}
try {
    $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
} catch (Throwable $exception) {
    error_log((string)$exception);
    Response::json(['error' => 'Internal server error'], 500);
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Models\Order;
use RuntimeException;

final class OrderController
{
    public static function create(): void
    {
        $user = Auth::user();
        if (($user['role'] ?? '') !== 'CUSTOMER') {
            Response::json(['error' => 'Only CUSTOMER can create orders'], 403);
        }
        $body = json_decode(file_get_contents('php://input'), true);
        $productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || !$quantity || $quantity < 1 || $quantity > 20) {
            Response::json(['error' => 'product_id and quantity (1..20) are required'], 400);
        }
        try {
            Response::json(['data' => Order::create((int)$user['sub'], $productId, $quantity)], 201);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 409);
        }
    }

    public static function index(): void
    {
        $user = Auth::user();
        $orders = in_array($user['role'] ?? '', ['ADMIN', 'ORDER_MANAGER', 'DELIVERY_MANAGER', 'FINANCE_OFFICER'], true)
            ? Order::all()
            : Order::forUser((int)$user['sub']);
        Response::json(['data' => $orders]);
    }

    public static function updateStatus(): void
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'ORDER_MANAGER'], true)) {
            Response::json(['error' => 'Only ADMIN or ORDER_MANAGER can update order status'], 403);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $orderId = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT);
        $status = strtoupper((string)($body['status'] ?? ''));
        if (!$orderId || !in_array($status, ['PENDING', 'PAID', 'SHIPPED', 'CANCELLED'], true)) {
            Response::json(['error' => 'order_id and valid status are required'], 400);
        }

        try {
            $updated = Order::updateStatus((int)$orderId, $status);
            Response::json(['data' => $updated]);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 404);
        }
    }
}

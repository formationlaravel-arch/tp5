<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Models\Delivery;
use RuntimeException;

final class DeliveryController
{
    public static function create(): void
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'DELIVERY_MANAGER'], true)) {
            Response::json(['error' => 'Only ADMIN or DELIVERY_MANAGER can create deliveries'], 403);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $orderId = filter_var($body['order_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$orderId) {
            Response::json(['error' => 'order_id is required'], 400);
        }

        try {
            Response::json(['data' => Delivery::create((int)$orderId, (int)$user['sub'])], 201);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 404);
        }
    }

    public static function updateStatus(): void
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'DELIVERY_MANAGER'], true)) {
            Response::json(['error' => 'Only ADMIN or DELIVERY_MANAGER can update deliveries'], 403);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $deliveryId = filter_var($body['delivery_id'] ?? null, FILTER_VALIDATE_INT);
        $status = strtoupper((string)($body['status'] ?? ''));
        if (!$deliveryId || !in_array($status, ['PENDING', 'IN_TRANSIT', 'DELIVERED'], true)) {
            Response::json(['error' => 'delivery_id and valid status are required'], 400);
        }

        try {
            Response::json(['data' => Delivery::updateStatus((int)$deliveryId, $status)]);
        } catch (RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 409);
        }
    }

    public static function index(): void
    {
        $user = Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'DELIVERY_MANAGER'], true)) {
            Response::json(['error' => 'Only ADMIN or DELIVERY_MANAGER can list deliveries'], 403);
        }
        Response::json(['data' => Delivery::all()]);
    }
}

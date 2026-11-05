<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Models\Product;

final class ProductController
{
    public static function index(): void
    {
        Response::json(['data' => Product::all()]);
    }

    public static function restock(): void
    {
        $user = \App\Core\Auth::user();
        if (!in_array($user['role'] ?? '', ['ADMIN', 'INVENTORY_MANAGER'], true)) {
            Response::json(['error' => 'Only ADMIN or INVENTORY_MANAGER can restock'], 403);
        }

        $body = json_decode(file_get_contents('php://input'), true);
        $productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || !$quantity || $quantity < 1 || $quantity > 1000) {
            Response::json(['error' => 'product_id and quantity (1..1000) are required'], 400);
        }

        try {
            Response::json(['data' => Product::restock((int)$productId, (int)$quantity)]);
        } catch (\RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 404);
        }
    }
}

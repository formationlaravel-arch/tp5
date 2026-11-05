<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class Order
{
    public static function create(int $userId, int $productId, int $quantity): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $query = $pdo->prepare('SELECT name, price, stock FROM products WHERE id = ? FOR UPDATE');
            $query->execute([$productId]);
            $product = $query->fetch();
            if (!$product || (int)$product['stock'] < $quantity) {
                throw new RuntimeException('Product not found or insufficient stock.');
            }
            $total = (float)$product['price'] * $quantity;
            $update = $pdo->prepare('UPDATE products SET stock = stock - ? WHERE id = ?');
            $update->execute([$quantity, $productId]);
            $insert = $pdo->prepare('INSERT INTO orders (user_id, product_id, quantity, unit_price, total) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$userId, $productId, $quantity, $product['price'], number_format($total, 2, '.', '')]);
            $id = (int)$pdo->lastInsertId();
            $payment = $pdo->prepare(
                "INSERT INTO payments (order_id, amount, status, processed_by) VALUES (?, ?, 'PENDING', ?)"
            );
            $payment->execute([$id, number_format($total, 2, '.', ''), $userId]);
            $pdo->commit();
            return [
                'id' => $id,
                'user_id' => $userId,
                'product_id' => $productId,
                'product_name' => $product['name'],
                'quantity' => $quantity,
                'total' => number_format($total, 2, '.', ''),
                'status' => 'PENDING'
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function updateStatus(int $orderId, string $status): array
    {
        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT id FROM orders WHERE id = ?');
        $exists->execute([$orderId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Order not found.');
        }
        $query = $pdo->prepare('UPDATE orders SET status = ? WHERE id = ?');
        $query->execute([$status, $orderId]);
        return ['id' => $orderId, 'status' => $status];
    }

    public static function forUser(int $userId): array
    {
        $query = Database::connection()->prepare(
            'SELECT o.id, o.product_id, p.name AS product_name, o.quantity, o.total, o.status, o.created_at
             FROM orders o JOIN products p ON p.id = o.product_id
             WHERE o.user_id = ? ORDER BY o.id DESC'
        );
        $query->execute([$userId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function all(): array
    {
        return Database::connection()->query(
            'SELECT o.id, o.user_id, o.product_id, p.name AS product_name, o.quantity, o.total, o.status, o.created_at
             FROM orders o JOIN products p ON p.id = o.product_id ORDER BY o.id DESC'
        )->fetchAll();
    }
}

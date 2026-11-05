<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class Payment
{
    public static function record(int $orderId, float $amount, string $status, int $processedBy): array
    {
        $order = Database::connection()->prepare('SELECT id FROM orders WHERE id = ?');
        $order->execute([$orderId]);
        if (!$order->fetchColumn()) {
            throw new RuntimeException('Order not found.');
        }
        $statement = Database::connection()->prepare('INSERT INTO payments (order_id, amount, status, processed_by) VALUES (?, ?, ?, ?)');
        $statement->execute([$orderId, number_format($amount, 2, '.', ''), $status, $processedBy]);
        $id = (int)Database::connection()->lastInsertId();
        return ['id' => $id, 'order_id' => $orderId, 'amount' => number_format($amount, 2, '.', ''), 'status' => $status, 'processed_by' => $processedBy];
    }

    public static function all(): array
    {
        return Database::connection()->query('SELECT p.id, p.order_id, p.amount, p.status, p.processed_by FROM payments p ORDER BY p.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }
}

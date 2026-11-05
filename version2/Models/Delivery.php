<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;
use RuntimeException;

final class Delivery
{
    public static function create(int $orderId, int $assignedTo): array
    {
        $order = Database::connection()->prepare('SELECT id FROM orders WHERE id = ?');
        $order->execute([$orderId]);
        if (!$order->fetchColumn()) {
            throw new RuntimeException('Order not found.');
        }
        $trackingCode = 'TRK-' . strtoupper(bin2hex(random_bytes(6)));
        $statement = Database::connection()->prepare('INSERT INTO deliveries (order_id, assigned_to, tracking_code, status) VALUES (?, ?, ?, "IN_TRANSIT")');
        $statement->execute([$orderId, $assignedTo, $trackingCode]);
        $id = (int)Database::connection()->lastInsertId();
        return ['id' => $id, 'order_id' => $orderId, 'assigned_to' => $assignedTo, 'tracking_code' => $trackingCode, 'status' => 'IN_TRANSIT'];
    }

    public static function updateStatus(int $deliveryId, string $status): array
    {
        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT id FROM deliveries WHERE id = ?');
        $exists->execute([$deliveryId]);
        if (!$exists->fetchColumn()) {
            throw new RuntimeException('Delivery not found.');
        }
        $statement = $pdo->prepare('UPDATE deliveries SET status = ? WHERE id = ?');
        $statement->execute([$status, $deliveryId]);
        return ['id' => $deliveryId, 'status' => $status];
    }

    public static function all(): array
    {
        return Database::connection()->query('SELECT d.id, d.order_id, d.assigned_to, d.tracking_code, d.status FROM deliveries d ORDER BY d.id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }
}

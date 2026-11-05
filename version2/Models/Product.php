<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Product
{
    public static function all(): array
    {
        return Database::connection()->query('SELECT id, name, price, stock FROM products ORDER BY id')->fetchAll();
    }

    public static function restock(int $productId, int $quantity): array
    {
        $statement = Database::connection()->prepare('UPDATE products SET stock = stock + ? WHERE id = ?');
        $statement->execute([$quantity, $productId]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Product not found.');
        }
        $product = Database::connection()->prepare('SELECT id, name, price, stock FROM products WHERE id = ?');
        $product->execute([$productId]);
        return $product->fetch() ?: [];
    }
}

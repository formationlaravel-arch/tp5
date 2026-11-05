<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public static function findByUsername(string $username): ?array
    {
        $statement = Database::connection()->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $statement->execute([$username]);
        $user = $statement->fetch();
        return $user ?: null;
    }
}

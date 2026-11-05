<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $host = getenv('V2_DB_HOST') ?: '127.0.0.1';
            $name = getenv('V2_DB_NAME') ?: 'mpin_api_mvc_db';
            self::$connection = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                getenv('V2_DB_USER') ?: 'root',
                getenv('V2_DB_PASS') ?: '',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        }
        return self::$connection;
    }
}

<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    public static function user(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._-]+)$/i', $header, $matches)) {
            Response::json(['error' => 'Bearer token required'], 401);
        }
        try {
            return Jwt::verify($matches[1]);
        } catch (\RuntimeException $exception) {
            Response::json(['error' => $exception->getMessage()], 401);
        }
    }

    public static function requireRole(string $role): array
    {
        $user = self::user();
        if (($user['role'] ?? '') !== $role) {
            Response::json(['error' => 'Forbidden'], 403);
        }
        return $user;
    }
}

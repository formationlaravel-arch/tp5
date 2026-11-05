<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Jwt;
use App\Core\Response;
use App\Models\User;

final class AuthController
{
    public static function me(): void
    {
        $claims = \App\Core\Auth::user();
        Response::json(['data' => [
            'id' => (int)$claims['sub'],
            'username' => (string)$claims['username'],
            'role' => (string)$claims['role']
        ]]);
    }

    public static function login(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body) || !is_string($body['username'] ?? null) || !is_string($body['password'] ?? null)) {
            Response::json(['error' => 'username and password are required'], 400);
        }
        $user = User::findByUsername(trim($body['username']));
        if (!$user || !password_verify($body['password'], $user['password_hash'])) {
            Response::json(['error' => 'Invalid credentials'], 401);
        }
        Response::json(['access_token' => Jwt::issue($user), 'token_type' => 'Bearer', 'expires_in' => 3600]);
    }
}

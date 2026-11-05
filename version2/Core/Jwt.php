<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Jwt
{
    private static function secret(): string
    {
        $secret = getenv('V2_JWT_SECRET') ?: 'local-demo-only-change-this-secret-32chars';
        if (strlen($secret) < 32) {
            throw new RuntimeException('V2_JWT_SECRET must contain at least 32 characters.');
        }
        return $secret;
    }

    private static function encodePart(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function issue(array $user): string
    {
        $now = time();
        $header = self::encodePart(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = self::encodePart([
            'iss' => 'mpin-api-mvc',
            'sub' => (int)$user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'iat' => $now,
            'exp' => $now + 3600
        ]);
        $body = $header . '.' . $payload;
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, self::secret(), true)), '+/', '-_'), '=');
        return $body . '.' . $signature;
    }

    public static function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed bearer token.');
        }
        [$headerPart, $payloadPart, $signature] = $parts;
        $header = json_decode(base64_decode(strtr($headerPart, '-_', '+/'), true) ?: '', true);
        $payload = json_decode(base64_decode(strtr($payloadPart, '-_', '+/'), true) ?: '', true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256' || !is_array($payload)) {
            throw new RuntimeException('Invalid token structure.');
        }
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $headerPart . '.' . $payloadPart, self::secret(), true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $signature) || ($payload['iss'] ?? null) !== 'mpin-api-mvc') {
            throw new RuntimeException('Invalid token signature or issuer.');
        }
        if (!isset($payload['exp'], $payload['sub'], $payload['role']) || (int)$payload['exp'] <= time()) {
            throw new RuntimeException('Expired or incomplete token.');
        }
        return $payload;
    }
}

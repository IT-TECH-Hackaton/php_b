<?php

namespace App\Utils;

use App\Config\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Ramsey\Uuid\Uuid;

class JWTUtil
{
    public static function generateToken(string $userId, string $email, string $role): string
    {
        $issuedAt = time();
        $expiration = $issuedAt + Config::$jwtExpiration;

        $payload = [
            'userId' => $userId,
            'email' => $email,
            'role' => $role,
            'iat' => $issuedAt,
            'exp' => $expiration,
        ];

        return JWT::encode($payload, Config::$jwtSecret, 'HS256');
    }

    public static function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key(Config::$jwtSecret, 'HS256'));
            return (array)$decoded;
        } catch (\Exception $e) {
            return null;
        }
    }
}



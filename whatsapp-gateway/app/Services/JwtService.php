<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

final class JwtService
{
    private const ALGORITHM = 'HS256';

    public static function generate(int $userId): string
    {
        if ($userId <= 0) {
            throw new RuntimeException('Identifiant utilisateur invalide.');
        }

        $now = time();
        $ttl = (int) Config::get('JWT_TTL', '3600');

        if ($ttl <= 0) {
            throw new RuntimeException('Durée de validité JWT invalide.');
        }

        $payload = [
            'iss' => Config::require('APP_URL'),
            'sub' => (string) $userId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'jti' => bin2hex(random_bytes(16))
        ];

        return JWT::encode(
            $payload,
            Config::require('JWT_SECRET'),
            self::ALGORITHM
        );
    }

    public static function validate(string $token): object
    {
        if ($token === '') {
            throw new RuntimeException('Token JWT absent.');
        }

        return JWT::decode(
            $token,
            new Key(Config::require('JWT_SECRET'), self::ALGORITHM)
        );
    }

    private function __construct()
    {
    }
}

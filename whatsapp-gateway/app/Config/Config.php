<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class Config
{
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    public static function require(string $key): string
    {
        $value = self::get($key);

        if ($value === null || $value === '') {
            throw new RuntimeException(
                sprintf('La variable d’environnement "%s" est obligatoire.', $key)
            );
        }

        return (string) $value;
    }

    private function __construct()
    {
    }
}

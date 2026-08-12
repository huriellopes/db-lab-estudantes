<?php

namespace App\Core;

class Config
{
    /**
     * Lê uma variável de ambiente vinda do docker-compose (environment:) ou de um .env
     * carregado via phpdotenv (útil ao rodar a app fora do Docker, ex.: `php -S`).
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === null || $value === '') ? $default : $value;
    }
}

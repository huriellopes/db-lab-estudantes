<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $host = Config::get('DB_HOST', 'mysql');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::get('DB_NAME', 'schoolapp');
            $user = Config::get('DB_USER', 'appuser');
            $pass = Config::get('DB_PASS', '');

            try {
                self::$connection = new PDO(
                    "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ],
                );
            } catch (PDOException $e) {
                http_response_code(500);
                die('Erro ao conectar no banco de dados: ' . $e->getMessage());
            }
        }

        return self::$connection;
    }
}

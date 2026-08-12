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

    /**
     * Conexão nova (não é singleton, não é cacheada) autenticada como um usuário MySQL
     * específico — usada pelo console SQL do dashboard pra rodar comandos com as
     * credenciais reais da pessoa. Assim os GRANTs que o MySQL já aplica por schema (ver
     * SchemaProvisioner::createDatabase) barram sozinhos qualquer acesso fora do que ela é
     * dona, sem precisar reimplementar esse controle na aplicação.
     */
    public static function connectAs(string $username, string $password): PDO
    {
        $host = Config::get('DB_HOST', 'mysql');
        $port = Config::get('DB_PORT', '3306');

        return new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
}

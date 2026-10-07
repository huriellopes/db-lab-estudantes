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
                // die()/exit() com uma string sempre sai com status 0 em PHP — em CLI (ex.:
                // bin/console.php migrate, chamado em loop por docker/app-entrypoint.sh) isso
                // fazia o comando "ter sucesso" mesmo sem conectar, quebrando o retry: o
                // entrypoint seguia pro supervisord com o banco fora do ar e nenhuma migration
                // rodada. exit(1) explícito aqui faz o `until` do entrypoint tentar de novo.
                if (PHP_SAPI === 'cli') {
                    fwrite(STDERR, 'Erro ao conectar no banco de dados: ' . $e->getMessage() . PHP_EOL);
                    exit(1);
                }

                // Na web, o detalhe vai só pro log: a mensagem do PDO entrega host, porta e
                // usuário do banco (às vezes até o motivo exato da recusa) pra quem estiver
                // olhando a página — mesma regra do set_exception_handler de public/index.php.
                error_log('Erro ao conectar no banco de dados: ' . $e->getMessage());
                http_response_code(500);
                echo View::render('errors/500');
                exit;
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

<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    /** Ver connectAs(): limite de tempo de SELECT no console SQL, em milissegundos. */
    private const CONSOLE_MAX_EXECUTION_MS = 10_000;

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

        $pdo = new PDO(
            "mysql:host={$host};port={$port};charset=utf8mb4",
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Unbuffered: o resultado vem do servidor conforme é lido, em vez de inteiro
                // pra memória do PHP antes do primeiro fetch() — é o que deixa
                // App\Support\CappedResult parar de verdade no teto de linhas.
                self::mysqlAttribute('ATTR_USE_BUFFERED_QUERY') => false,
            ],
        );

        // Teto de tempo por SELECT nesta sessão: uma consulta pesada de um aluno (produto
        // cartesiano, SLEEP...) não segura o worker do PHP-FPM nem o MySQL compartilhado
        // pela turma. O MySQL aborta com o erro 3024, que o console mostra normalmente.
        $pdo->exec('SET SESSION max_execution_time = ' . self::CONSOLE_MAX_EXECUTION_MS);

        return $pdo;
    }

    /**
     * Constantes específicas do driver MySQL: Pdo\Mysql::ATTR_* a partir do PHP 8.4 (PDO::MYSQL_ATTR_*
     * é deprecated no 8.5, a imagem Docker de produção), PDO::MYSQL_ATTR_* antes disso (o
     * composer.json ainda aceita 8.2). O valor é o mesmo nos dois.
     */
    private static function mysqlAttribute(string $name): int
    {
        $modern = 'Pdo\\Mysql::' . $name;

        return defined($modern) ? constant($modern) : constant('PDO::MYSQL_' . $name);
    }
}

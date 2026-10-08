<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\Entities\User as UserEntity;
use App\Models\SchemaRecord;
use App\Services\SchemaProvisioner;
use App\Services\UserManager;
use App\Support\Role;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

// uses(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Testes em tests/Integration falam com o MySQL de verdade (o do docker compose, porta
 * MYSQL_PORT do .env). Ficam fora do `composer test` padrão: rode `composer test:integration`.
 */
function requiresDatabase(): void
{
    if (getenv('INTEGRATION') !== '1') {
        test()->markTestSkipped('Teste de integração: rode com `composer test:integration` (precisa do docker compose no ar).');
    }

    static $ready = false;
    if ($ready) {
        return;
    }

    $file = dirname(__DIR__) . '/.env';
    $env = is_file($file) ? Dotenv\Dotenv::parse((string) file_get_contents($file)) : [];
    $config = [
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => $env['MYSQL_PORT'] ?? '3307',
        'DB_NAME' => $env['MYSQL_DATABASE'] ?? 'schoolapp',
        'DB_USER' => $env['MYSQL_USER'] ?? 'appuser',
        'DB_PASS' => $env['MYSQL_PASSWORD'] ?? '',
    ];
    foreach ($config as $key => $value) {
        $_ENV[$key] = $value;
    }

    try {
        new PDO("mysql:host={$config['DB_HOST']};port={$config['DB_PORT']};dbname={$config['DB_NAME']}", $config['DB_USER'], $config['DB_PASS']);
    } catch (PDOException $e) {
        throw new RuntimeException('MySQL do docker compose não respondeu em 127.0.0.1:' . $config['DB_PORT'] . ' — suba com `docker compose up -d`. ' . $e->getMessage());
    }

    $ready = true;
}

function integrationUser(Role $role = Role::Aluno): UserEntity
{
    $suffix = bin2hex(random_bytes(4));

    return UserManager::provisionNewUser("Integração {$suffix}", "it-{$suffix}@example.test", "it{$suffix}", 'Integracao-Senha!9', $role);
}

function integrationSchema(UserEntity $owner, string $label = 'dados'): string
{
    $dbName = "{$owner->schemaPrefix}__{$label}";
    SchemaProvisioner::createDatabase($dbName, $owner->mysqlLogin);
    SchemaRecord::create($owner->id, $dbName);

    return $dbName;
}

function cleanupIntegrationData(): void
{
    $pdo = Database::connection();

    // Só o que os testes geram: login "it" + 8 hex. Nunca LIKE 'it%' — pegaria uma conta real
    // ("italo") e apagaria os databases dela no ambiente de dev.
    foreach ($pdo->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME REGEXP '^it[0-9a-f]{8}__' OR SCHEMA_NAME LIKE '\\_lixeira\\_s%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
        if (str_starts_with($db, '_lixeira_s')) {
            $owned = $pdo->prepare("SELECT 1 FROM deleted_models WHERE model = 'schema' AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.quarantine')) = ? AND label REGEXP '^it[0-9a-f]{8}__'");
            $owned->execute([$db]);
            if ($owned->fetch() === false) {
                continue; // quarentena que não é de teste: nunca mexer
            }
        }
        $pdo->exec("DROP DATABASE IF EXISTS `{$db}`");
    }

    foreach ($pdo->query("SELECT User FROM mysql.user WHERE User REGEXP '^it[0-9a-f]{8}$'")->fetchAll(PDO::FETCH_COLUMN) as $login) {
        SchemaProvisioner::dropMysqlAccount($login);
    }

    $pdo->exec("DELETE FROM deleted_models WHERE label REGEXP '^(Integração [0-9a-f]{8} <it-|it[0-9a-f]{8}__|q-it )'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'it-%@example.test'");
}

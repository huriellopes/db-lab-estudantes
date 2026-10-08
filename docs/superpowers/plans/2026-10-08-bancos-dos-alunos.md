# Bancos dos alunos para o professor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O professor vê os bancos dos alunos das instituições dele (tabelas, colunas, chaves, relações e dados) e insere/altera linhas, sem conseguir excluir nada.

**Architecture:** A conta MySQL do professor recebe `SELECT, INSERT, UPDATE` (nunca `DELETE/DROP/ALTER`) no prefixo de cada aluno com quem divide instituição; `ProfessorGrants` mantém isso sincronizado. As telas leem e escrevem pela conexão do próprio professor (`Database::connectAs` com a senha cifrada na sessão), então o "não excluir" é garantido pelo MySQL.

**Tech Stack:** PHP 8.5 sem framework, PDO/MySQL 8.0, Twig 3, Alpine.js + Axios + htmx, Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-08-bancos-dos-alunos-design.md`

## Global Constraints

- Privilégios do professor sobre bancos de aluno: exatamente `SELECT, INSERT, UPDATE`. Nunca `DELETE`, `DROP`, `ALTER`.
- Escopo: aluno de instituição em comum (`InstitutionScope::canSeeStudent`); fora disso, "não encontrado".
- Conexão do professor é unbuffered: sempre `fetchAll()` antes da próxima consulta.
- Auditoria sem os valores alterados (só banco, tabela, colunas, chave).
- Sincronização de permissões nunca derruba a ação principal.
- Telas sem reload; textos em português; lint limpo.

---

### Task 1: Regras puras (diferença de permissões e SQL de edição)

**Files:** Create `app/Support/ProfessorGrantDiff.php`, `app/Support/RowEditSql.php`; Test `tests/Unit/ProfessorGrantDiffTest.php`, `tests/Unit/RowEditSqlTest.php`.

**Interfaces — Produces:** `ProfessorGrantDiff::between(array $desired, array $current): array{grant: list<string>, revoke: list<string>}`; `RowEditSql::ident(string $name): string`, `RowEditSql::update(string $db, string $table, array $key, array $changes): array{0: string, 1: list<scalar|null>}`, `RowEditSql::insert(string $db, string $table, array $values): array{0: string, 1: list<scalar|null>}`.

- [ ] **Step 1: Testes que falham**

`tests/Unit/ProfessorGrantDiffTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\ProfessorGrantDiff;

it('grants what is missing and revokes what is left over', function () {
    expect(ProfessorGrantDiff::between(['ana\_\_%', 'bia\_\_%'], ['bia\_\_%', 'caio\_\_%']))
        ->toBe(['grant' => ['ana\_\_%'], 'revoke' => ['caio\_\_%']]);
});

it('does nothing when already in sync, and revokes everything when nothing is desired', function () {
    expect(ProfessorGrantDiff::between(['ana\_\_%'], ['ana\_\_%']))->toBe(['grant' => [], 'revoke' => []])
        ->and(ProfessorGrantDiff::between([], ['ana\_\_%']))->toBe(['grant' => [], 'revoke' => ['ana\_\_%']]);
});
```

`tests/Unit/RowEditSqlTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\RowEditSql;

it('quotes identifiers, escaping backticks', function () {
    expect(RowEditSql::ident('nota'))->toBe('`nota`')
        ->and(RowEditSql::ident('a`b'))->toBe('`a``b`');
});

it('builds an UPDATE limited to one row by the primary key, values as parameters', function () {
    [$sql, $params] = RowEditSql::update('ana__loja', 'item', ['pedido_id' => 7, 'linha' => 2], ['qtd' => '3', 'obs' => null]);

    expect($sql)->toBe('UPDATE `ana__loja`.`item` SET `qtd` = ?, `obs` = ? WHERE `pedido_id` = ? AND `linha` = ? LIMIT 1')
        ->and($params)->toBe(['3', null, 7, 2]);
});

it('builds an INSERT, including the empty one that uses only defaults', function () {
    expect(RowEditSql::insert('ana__loja', 'cliente', ['nome' => 'Bia', 'cidade' => null]))
        ->toBe(['INSERT INTO `ana__loja`.`cliente` (`nome`, `cidade`) VALUES (?, ?)', ['Bia', null]])
        ->and(RowEditSql::insert('ana__loja', 'log', []))->toBe(['INSERT INTO `ana__loja`.`log` () VALUES ()', []]);
});

it('refuses an UPDATE without key or without changes', function () {
    expect(fn () => RowEditSql::update('d', 't', [], ['a' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RowEditSql::update('d', 't', ['id' => 1], []))->toThrow(InvalidArgumentException::class);
});
```

Run: `vendor/bin/pest tests/Unit/ProfessorGrantDiffTest.php tests/Unit/RowEditSqlTest.php` → FAIL (classes não existem).

- [ ] **Step 2: Implementar**

`app/Support/ProfessorGrantDiff.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/** O que conceder e o que revogar para deixar as permissões de professor iguais ao desejado. */
final class ProfessorGrantDiff
{
    /**
     * @param list<string> $desired Padrões de prefixo (`<prefixo>\_\_%`) que o professor deve ter.
     * @param list<string> $current Padrões que ele tem hoje (só os "de professor": SELECT/INSERT/UPDATE).
     * @return array{grant: list<string>, revoke: list<string>}
     */
    public static function between(array $desired, array $current): array
    {
        $desired = array_values(array_unique($desired));
        $current = array_values(array_unique($current));

        return [
            'grant' => array_values(array_diff($desired, $current)),
            'revoke' => array_values(array_diff($current, $desired)),
        ];
    }
}
```

`app/Support/RowEditSql.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * SQL de inserir/alterar uma linha no banco de um aluno (tela do professor). Identificadores
 * entre crases (crase escapada); valores sempre como parâmetros. Não existe DELETE aqui — e a
 * conta do professor nem tem esse privilégio (ver App\Services\ProfessorGrants).
 */
final class RowEditSql
{
    public static function ident(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @param array<string, scalar> $key Coluna(s) da chave primária => valor atual.
     * @param array<string, scalar|null> $changes Coluna => novo valor (null = NULL).
     * @return array{0: string, 1: list<scalar|null>}
     */
    public static function update(string $db, string $table, array $key, array $changes): array
    {
        if ($key === [] || $changes === []) {
            throw new InvalidArgumentException('UPDATE precisa de chave e de pelo menos uma coluna alterada.');
        }

        $set = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $set[] = self::ident((string) $column) . ' = ?';
            $params[] = $value;
        }
        $where = [];
        foreach ($key as $column => $value) {
            $where[] = self::ident((string) $column) . ' = ?';
            $params[] = $value;
        }

        return [
            'UPDATE ' . self::ident($db) . '.' . self::ident($table) . ' SET ' . implode(', ', $set)
                . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params,
        ];
    }

    /**
     * @param array<string, scalar|null> $values Coluna => valor; colunas omitidas usam o padrão.
     * @return array{0: string, 1: list<scalar|null>}
     */
    public static function insert(string $db, string $table, array $values): array
    {
        $columns = array_map(static fn ($c): string => self::ident((string) $c), array_keys($values));

        return [
            'INSERT INTO ' . self::ident($db) . '.' . self::ident($table) . ' (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($values), '?')) . ')',
            array_values($values),
        ];
    }
}
```

Run: mesmos testes → PASS (6 testes).

- [ ] **Step 3: Commit** — `git add app/Support/ProfessorGrantDiff.php app/Support/RowEditSql.php tests/Unit/ProfessorGrantDiffTest.php tests/Unit/RowEditSqlTest.php && git commit -m "Regras puras: permissões de professor e SQL de edição de linha"`

---

### Task 2: `ProfessorGrants` (sincronização) e ganchos

**Files:** Create `app/Services/ProfessorGrants.php`, `tests/Integration/ProfessorGrantsTest.php`; Modify `app/Core/Console.php`, `docker/app-entrypoint.sh`, `app/Services/InstitutionManager.php`, `app/Services/ClassManager.php`, `app/Services/Archiver.php`, `app/Actions/Admin/UpdateAdminUserRoleAction.php`, `app/Actions/Auth/RegisterAction.php`, `bin/vincular-alunos-instituicao.php`.

**Interfaces — Produces:** `ProfessorGrants::desiredFor(int $userId): list<string>`, `ProfessorGrants::currentFor(string $login): list<string>`, `ProfessorGrants::syncUser(App\Models\Entities\User $user): array{grant: int, revoke: int}`, `ProfessorGrants::syncAll(): array{users: int, grant: int, revoke: int}`, `ProfessorGrants::syncAllQuietly(): void`; comando `grants:sync-professors`.

- [ ] **Step 1: Teste de integração que falha**

`tests/Integration/ProfessorGrantsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\InstitutionMember;
use App\Services\InstitutionManager;
use App\Services\ProfessorGrants;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('lets the professor read, insert and update the student data, but never delete or drop', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    $db = integrationSchema($student);
    Database::connection()->exec("CREATE TABLE `{$db}`.nota (id INT PRIMARY KEY, valor INT)");
    Database::connection()->exec("INSERT INTO `{$db}`.nota VALUES (1, 5)");
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);

    expect(ProfessorGrants::currentFor($professor->mysqlLogin))->toBe([App\Support\SchemaNameBuilder::grantPattern($student->schemaPrefix)]);

    $pdo = Database::connectAs($professor->mysqlLogin, 'Integracao-Senha!9');
    $pdo->exec("UPDATE `{$db}`.nota SET valor = 9 WHERE id = 1");
    $pdo->exec("INSERT INTO `{$db}`.nota VALUES (2, 7)");
    $rows = $pdo->query("SELECT valor FROM `{$db}`.nota ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    expect(array_map('intval', $rows))->toBe([9, 7]);

    foreach (["DELETE FROM `{$db}`.nota", "DROP TABLE `{$db}`.nota", "TRUNCATE TABLE `{$db}`.nota", "ALTER TABLE `{$db}`.nota ADD x INT", "DROP DATABASE `{$db}`"] as $forbidden) {
        expect(fn () => $pdo->exec($forbidden))->toThrow(PDOException::class);
    }
    expect((int) Database::connection()->query("SELECT COUNT(*) FROM `{$db}`.nota")->fetchColumn())->toBe(2);
});

it('revokes the access when the student leaves the institution', function () {
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);
    $membership = array_values(array_filter(InstitutionMember::forInstitution($inst), static fn ($m) => $m['user_id'] === $student->id))[0];

    InstitutionManager::removeMember($membership['id']);

    expect(ProfessorGrants::currentFor($professor->mysqlLogin))->toBe([]);
});

it('gives nothing for students of another institution and is idempotent', function () {
    [$mine, $other] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    $foreigner = integrationUser();
    InstitutionManager::addMember($mine, $professor->email);
    InstitutionManager::addMember($other, $foreigner->email);

    expect(ProfessorGrants::desiredFor($professor->id))->toBe([])
        ->and(ProfessorGrants::syncUser(App\Models\User::find($professor->id)))->toBe(['grant' => 0, 'revoke' => 0]);
});
```

Run: `INTEGRATION=1 vendor/bin/pest tests/Integration/ProfessorGrantsTest.php` → FAIL (classe não existe).

- [ ] **Step 2: Implementar o serviço**

`app/Services/ProfessorGrants.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\Entities\User as UserEntity;
use App\Models\User;
use App\Support\ErrorLogger;
use App\Support\ProfessorGrantDiff;
use App\Support\Role;
use App\Support\SchemaNameBuilder;
use PDO;
use Throwable;

/**
 * Permissões da conta MySQL do professor sobre os bancos dos alunos das instituições dele:
 * exatamente SELECT, INSERT, UPDATE no prefixo de cada aluno — nunca DELETE, DROP ou ALTER. É o
 * que garante, no próprio MySQL, que o professor ajuda (vê e edita dados) mas não exclui nada,
 * na tela da plataforma, no phpMyAdmin ou num SGBD.
 *
 * Recalculado do zero a cada mudança de vínculo (idempotente): o desejado sai das tabelas de
 * instituição, o atual de mysql.db (linhas com Select/Insert/Update = Y e Delete/Drop/Alter = N —
 * o GRANT do próprio prefixo de cada conta é ALL, então nunca entra nessa conta).
 */
final class ProfessorGrants
{
    /** @return list<string> */
    public static function desiredFor(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT u.schema_prefix FROM institution_members p
             INNER JOIN institution_members s ON s.institution_id = p.institution_id AND s.role = 'aluno'
             INNER JOIN users u ON u.id = s.user_id AND u.role = 'aluno'
             WHERE p.user_id = ? AND p.role = 'professor' ORDER BY u.schema_prefix",
        );
        $stmt->execute([$userId]);

        return array_map(SchemaNameBuilder::grantPattern(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> */
    public static function currentFor(string $login): array
    {
        $stmt = Database::connection()->prepare(
            "SELECT Db FROM mysql.db WHERE User = ? AND Host = '%'
               AND Select_priv = 'Y' AND Insert_priv = 'Y' AND Update_priv = 'Y'
               AND Delete_priv = 'N' AND Drop_priv = 'N' AND Alter_priv = 'N'
             ORDER BY Db",
        );
        $stmt->execute([$login]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{grant: int, revoke: int} */
    public static function syncUser(UserEntity $user): array
    {
        $desired = $user->role === Role::Professor ? self::desiredFor($user->id) : [];
        $diff = ProfessorGrantDiff::between($desired, self::currentFor($user->mysqlLogin));
        $pdo = Database::connection();
        $account = $pdo->quote($user->mysqlLogin) . "@'%'";

        foreach ($diff['grant'] as $pattern) {
            $pdo->exec('GRANT SELECT, INSERT, UPDATE ON `' . str_replace('`', '``', $pattern) . "`.* TO {$account}");
        }
        foreach ($diff['revoke'] as $pattern) {
            $pdo->exec('REVOKE SELECT, INSERT, UPDATE ON `' . str_replace('`', '``', $pattern) . "`.* FROM {$account}");
        }

        return ['grant' => count($diff['grant']), 'revoke' => count($diff['revoke'])];
    }

    /** @return array{users: int, grant: int, revoke: int} */
    public static function syncAll(): array
    {
        $total = ['users' => 0, 'grant' => 0, 'revoke' => 0];
        foreach (User::all() as $user) {
            if ($user->role === Role::Admin) {
                continue;
            }
            try {
                $done = self::syncUser($user);
            } catch (Throwable $e) {
                ErrorLogger::exception($e, 'error'); // uma conta com problema não impede as outras
                continue;
            }
            $total['users']++;
            $total['grant'] += $done['grant'];
            $total['revoke'] += $done['revoke'];
        }

        return $total;
    }

    /** Depois de mudar vínculos: sincroniza sem nunca derrubar a ação principal. */
    public static function syncAllQuietly(): void
    {
        try {
            self::syncAll();
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'error');
        }
    }
}
```

- [ ] **Step 3: Ganchos**

- `app/Services/InstitutionManager.php`: chamar `ProfessorGrants::syncAllQuietly();` no fim de `addMember()` (antes do `return $user;`), de `removeMember()` e de `delete()`.
- `app/Actions/Admin/UpdateAdminUserRoleAction.php`: `use App\Services\ProfessorGrants;` e `ProfessorGrants::syncAllQuietly();` logo depois de `UserModel::updateRole(...)` (o papel novo precisa estar gravado).
- `app/Services/ClassManager.php`: em `joinByCode()`, depois de cada `InstitutionMember::add(...)`, `ProfessorGrants::syncAllQuietly();`.
- `app/Actions/Auth/RegisterAction.php`: `use App\Services\ProfessorGrants;` e, depois de `InstitutionMember::add(...)` (cadastro com código), `ProfessorGrants::syncAllQuietly();`.
- `app/Services/Archiver.php`: no fim de `archive()` (antes do `return $batchId;`) e de `restore()` (depois do `AuditLog::record('archive.restored', ...)`), se algum item for `user`, `institution` ou `institution_member`: `ProfessorGrants::syncAllQuietly();` — com o helper:

```php
    /** @param list<array<string, mixed>> $items */
    private static function touchesMemberships(array $items): bool
    {
        foreach ($items as $item) {
            if (in_array($item['model'], ['user', 'institution', 'institution_member'], true)) {
                return true;
            }
        }

        return false;
    }
```

- `bin/vincular-alunos-instituicao.php`: no fim, se `$apply && $linked > 0 && class_exists(\App\Services\ProfessorGrants::class)`: `\App\Services\ProfessorGrants::syncAll();` e imprimir "Permissões dos professores sincronizadas.".
- `app/Core/Console.php`: comando `'grants:sync-professors' => $this->syncProfessorGrants(),` com:

```php
    /** Recalcula as permissões dos professores sobre os bancos dos alunos (idempotente; roda no boot). */
    private function syncProfessorGrants(): int
    {
        $done = ProfessorGrants::syncAll();
        $this->line("Permissões de professores sincronizadas: {$done['users']} conta(s), {$done['grant']} concedida(s), {$done['revoke']} revogada(s).");

        return 0;
    }
```

  (e `use App\Services\ProfessorGrants;`; acrescentar `grants:sync-professors` ao texto de `usage()`).
- `docker/app-entrypoint.sh`: logo depois de `echo "Migrations em dia."`:

```sh
# Permissões dos professores sobre os bancos dos alunos (idempotente) — ver ProfessorGrants.
php bin/console.php grants:sync-professors || echo "Falha ao sincronizar permissões de professores — a app sobe mesmo assim." >&2
```

- [ ] **Step 4: Rodar** — `INTEGRATION=1 vendor/bin/pest tests/Integration` e `vendor/bin/pest` (exit 0), lint limpo.

- [ ] **Step 5: Commit** — `git add -A app bin docker tests && git commit -m "Professor recebe SELECT/INSERT/UPDATE (sem DELETE/DROP) nos bancos dos alunos das instituições dele"`

---

### Task 3: Leitura da estrutura/dados e edição de linhas

**Files:** Create `app/Services/StudentDatabases.php`, `app/Services/StudentDataEditor.php`, `app/Services/StudentDataException.php`, `tests/Integration/StudentDatabasesTest.php`.

**Interfaces — Produces:**
- `StudentDatabases::visibleTo(AuthenticatedUser $prof): list<array{institution: string, students: list<array{id: int, name: string, email: string, schemas: list<string>}>}>`
- `StudentDatabases::canAccess(AuthenticatedUser $prof, string $db): bool`
- `StudentDatabases::ownerName(string $db): ?string`
- `StudentDatabases::structure(PDO $pdo, string $db): list<array{name: string, rows: int, primaryKey: list<string>, columns: list<array{name: string, type: string, nullable: bool, key: string, default: ?string, extra: string, binary: bool}>, foreignKeys: list<array{column: string, refTable: string, refColumn: string}>}>`
- `StudentDatabases::table(PDO $pdo, string $db, string $table): ?array` (um item de `structure`)
- `StudentDatabases::rows(PDO $pdo, string $db, array $table, int $page, int $perPage = 25): array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}`
- `StudentDataEditor::update(PDO $pdo, AuthenticatedUser $prof, string $db, string $table, array $key, array $values, array $nulls): void`
- `StudentDataEditor::insert(PDO $pdo, AuthenticatedUser $prof, string $db, string $table, array $values, array $nulls): void`
- `StudentDataException extends RuntimeException` (mensagem segura)

- [ ] **Step 1: Teste de integração que falha**

`tests/Integration/StudentDatabasesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\InstitutionManager;
use App\Services\StudentDataEditor;
use App\Services\StudentDataException;
use App\Services\StudentDatabases;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

/** @return array{0: App\Support\AuthenticatedUser, 1: string, 2: PDO} professor, banco do aluno, conexão do professor */
function studentDbFixture(): array
{
    $inst = integrationInstitution();
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();
    $db = integrationSchema($student);
    $app = Database::connection();
    $app->exec("CREATE TABLE `{$db}`.cliente (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(40) NOT NULL, cidade VARCHAR(40) NULL)");
    $app->exec("CREATE TABLE `{$db}`.pedido (id INT PRIMARY KEY, cliente_id INT, FOREIGN KEY (cliente_id) REFERENCES `{$db}`.cliente(id))");
    $app->exec("CREATE TABLE `{$db}`.sem_pk (x INT)");
    $app->exec("INSERT INTO `{$db}`.cliente (nome, cidade) VALUES ('Ana', 'Recife'), ('Bia', NULL)");
    InstitutionManager::addMember($inst, $professor->email);
    InstitutionManager::addMember($inst, $student->email);

    return [asActor($professor), $db, Database::connectAs($professor->mysqlLogin, 'Integracao-Senha!9')];
}

it('shows tables, columns, primary keys and relations of a student database', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    expect(StudentDatabases::canAccess($prof, $db))->toBeTrue();
    $tables = array_column(StudentDatabases::structure($pdo, $db), null, 'name');

    expect(array_keys($tables))->toBe(['cliente', 'pedido', 'sem_pk'])
        ->and($tables['cliente']['primaryKey'])->toBe(['id'])
        ->and(array_column($tables['cliente']['columns'], 'name'))->toBe(['id', 'nome', 'cidade'])
        ->and($tables['cliente']['columns'][2]['nullable'])->toBeTrue()
        ->and($tables['pedido']['foreignKeys'])->toBe([['column' => 'cliente_id', 'refTable' => 'cliente', 'refColumn' => 'id']])
        ->and($tables['sem_pk']['primaryKey'])->toBe([]);

    $page = StudentDatabases::rows($pdo, $db, $tables['cliente'], 1);
    expect($page['total'])->toBe(2)
        ->and(array_column($page['rows'], 'nome'))->toBe(['Ana', 'Bia']);
});

it('updates and inserts rows as the professor, and audits without the values', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    StudentDataEditor::update($pdo, $prof, $db, 'cliente', ['id' => '2'], ['nome' => 'Beatriz', 'cidade' => 'qualquer'], ['cidade' => '1']);
    StudentDataEditor::insert($pdo, $prof, $db, 'cliente', ['nome' => 'Caio', 'cidade' => '', 'id' => ''], []);

    $rows = Database::connection()->query("SELECT id, nome, cidade FROM `{$db}`.cliente ORDER BY id")->fetchAll();
    expect($rows)->toBe([
        ['id' => 1, 'nome' => 'Ana', 'cidade' => 'Recife'],
        ['id' => 2, 'nome' => 'Beatriz', 'cidade' => null],
        ['id' => 3, 'nome' => 'Caio', 'cidade' => null],
    ]);

    $meta = (string) Database::connection()->query("SELECT meta FROM audit_logs WHERE action = 'professor.db_row_updated' ORDER BY id DESC LIMIT 1")->fetchColumn();
    expect(json_decode($meta, true)['tabela'])->toBe('cliente')
        ->and($meta)->not->toContain('Beatriz');
});

it('refuses editing without primary key, unknown columns and students of other institutions', function () {
    [$prof, $db, $pdo] = studentDbFixture();

    expect(fn () => StudentDataEditor::update($pdo, $prof, $db, 'sem_pk', ['x' => '1'], ['x' => '2'], []))->toThrow(StudentDataException::class, 'chave primária')
        ->and(fn () => StudentDataEditor::update($pdo, $prof, $db, 'cliente', ['id' => '1'], ['senha' => 'x'], []))->toThrow(StudentDataException::class, 'Nenhuma coluna')
        ->and(fn () => StudentDataEditor::insert($pdo, $prof, $db, 'tabela_que_nao_existe', ['a' => '1'], []))->toThrow(StudentDataException::class, 'não encontrada');

    $outsider = integrationUser();
    $otherDb = integrationSchema($outsider);
    expect(StudentDatabases::canAccess($prof, $otherDb))->toBeFalse();
});
```

Run: `INTEGRATION=1 vendor/bin/pest tests/Integration/StudentDatabasesTest.php` → FAIL.

- [ ] **Step 2: Implementar**

`app/Services/StudentDataException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Erro esperado ao ver/editar o banco de um aluno — mensagem segura pra mostrar ao professor. */
final class StudentDataException extends RuntimeException
{
}
```

`app/Services/StudentDatabases.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\InstitutionMember;
use App\Models\SchemaRecord;
use App\Support\AuthenticatedUser;
use App\Support\InstitutionScope;
use App\Support\RowEditSql;
use App\Support\Role;
use PDO;

/**
 * Leitura dos bancos dos alunos para a tela do professor. Estrutura e dados vêm pela conexão do
 * PRÓPRIO professor (permissões de ProfessorGrants): o MySQL só mostra o que ele pode ver.
 * A conexão é unbuffered (Database::connectAs) — toda consulta aqui termina em fetchAll().
 */
final class StudentDatabases
{
    private const BINARY_TYPES = ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bit', 'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon', 'geometrycollection'];

    /** @return list<array{institution: string, students: list<array{id: int, name: string, email: string, schemas: list<string>}>}> */
    public static function visibleTo(AuthenticatedUser $prof): array
    {
        if ($prof->role !== Role::Professor) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            "SELECT i.name AS institution, u.id, u.name, u.email, sc.db_name
             FROM institution_members p
             INNER JOIN institutions i ON i.id = p.institution_id
             INNER JOIN institution_members s ON s.institution_id = p.institution_id AND s.role = 'aluno'
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN schemas_criados sc ON sc.user_id = u.id
             WHERE p.user_id = ? AND p.role = 'professor'
             ORDER BY i.name, u.name, sc.db_name",
        );
        $stmt->execute([$prof->id]);

        $grouped = [];
        foreach ($stmt->fetchAll() as $r) {
            $student = &$grouped[$r['institution']][(int) $r['id']];
            $student ??= ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'email' => (string) $r['email'], 'schemas' => []];
            if ($r['db_name'] !== null) {
                $student['schemas'][] = (string) $r['db_name'];
            }
            unset($student);
        }

        $out = [];
        foreach ($grouped as $institution => $students) {
            $out[] = ['institution' => (string) $institution, 'students' => array_values($students)];
        }

        return $out;
    }

    public static function canAccess(AuthenticatedUser $prof, string $db): bool
    {
        $schema = SchemaRecord::findByName($db);

        return $schema !== null && $prof->role === Role::Professor && InstitutionScope::canSeeStudent(
            $prof->role,
            InstitutionMember::institutionIdsOf($prof->id),
            InstitutionMember::institutionOfStudent($schema->userId),
        );
    }

    public static function ownerName(string $db): ?string
    {
        $stmt = Database::connection()->prepare('SELECT u.name FROM schemas_criados s INNER JOIN users u ON u.id = s.user_id WHERE s.db_name = ?');
        $stmt->execute([$db]);
        $name = $stmt->fetchColumn();

        return $name === false ? null : (string) $name;
    }

    /** Conexão como o professor logado; null quando a senha MySQL não está em cache na sessão. */
    public static function connectionFor(AuthenticatedUser $prof): ?PDO
    {
        $password = Auth::mysqlPassword();

        return $password === null ? null : Database::connectAs($prof->mysqlLogin, $password);
    }

    /** @return list<array{name: string, rows: int, primaryKey: list<string>, columns: list<array<string, mixed>>, foreignKeys: list<array{column: string, refTable: string, refColumn: string}>}> */
    public static function structure(PDO $pdo, string $db): array
    {
        $q = static function (string $sql) use ($pdo, $db): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$db]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };

        $tables = [];
        foreach ($q("SELECT TABLE_NAME, COALESCE(TABLE_ROWS, 0) AS approx FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $t) {
            $tables[$t['TABLE_NAME']] = ['name' => (string) $t['TABLE_NAME'], 'rows' => (int) $t['approx'], 'primaryKey' => [], 'columns' => [], 'foreignKeys' => []];
        }
        foreach ($q('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, DATA_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION') as $c) {
            if (!isset($tables[$c['TABLE_NAME']])) {
                continue; // view
            }
            $tables[$c['TABLE_NAME']]['columns'][] = [
                'name' => (string) $c['COLUMN_NAME'],
                'type' => (string) $c['COLUMN_TYPE'],
                'nullable' => $c['IS_NULLABLE'] === 'YES',
                'key' => (string) $c['COLUMN_KEY'],
                'default' => $c['COLUMN_DEFAULT'] !== null ? (string) $c['COLUMN_DEFAULT'] : null,
                'extra' => (string) $c['EXTRA'],
                'binary' => in_array(strtolower((string) $c['DATA_TYPE']), self::BINARY_TYPES, true),
            ];
        }
        foreach ($q("SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION") as $k) {
            if (!isset($tables[$k['TABLE_NAME']])) {
                continue;
            }
            if ($k['CONSTRAINT_NAME'] === 'PRIMARY') {
                $tables[$k['TABLE_NAME']]['primaryKey'][] = (string) $k['COLUMN_NAME'];
            } elseif ($k['REFERENCED_TABLE_NAME'] !== null) {
                $tables[$k['TABLE_NAME']]['foreignKeys'][] = ['column' => (string) $k['COLUMN_NAME'], 'refTable' => (string) $k['REFERENCED_TABLE_NAME'], 'refColumn' => (string) $k['REFERENCED_COLUMN_NAME']];
            }
        }

        return array_values($tables);
    }

    /** @return ?array<string, mixed> */
    public static function table(PDO $pdo, string $db, string $table): ?array
    {
        foreach (self::structure($pdo, $db) as $t) {
            if ($t['name'] === $table) {
                return $t;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $table Item de structure().
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int}
     */
    public static function rows(PDO $pdo, string $db, array $table, int $page, int $perPage = 25): array
    {
        $from = RowEditSql::ident($db) . '.' . RowEditSql::ident($table['name']);
        $total = (int) $pdo->query("SELECT COUNT(*) FROM {$from}")->fetchAll(PDO::FETCH_COLUMN)[0];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $order = $table['primaryKey'] !== [] ? ' ORDER BY ' . implode(', ', array_map(RowEditSql::ident(...), $table['primaryKey'])) : '';
        $rows = $pdo->query("SELECT * FROM {$from}{$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage))->fetchAll(PDO::FETCH_ASSOC);

        $binary = array_column(array_filter($table['columns'], static fn ($c) => $c['binary']), 'name');
        foreach ($rows as &$row) {
            foreach ($binary as $column) {
                if ($row[$column] !== null) {
                    $row[$column] = '[binário]';
                }
            }
        }
        unset($row);

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }
}
```

`app/Services/StudentDataEditor.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Support\AuthenticatedUser;
use App\Support\RowEditSql;
use PDO;
use PDOException;

/**
 * Inserir/alterar linhas no banco de um aluno, como o professor (a conta dele não tem DELETE —
 * ver ProfessorGrants). Tabela e colunas são conferidas contra a estrutura real antes de montar
 * o SQL; valores sempre como parâmetros. A auditoria registra onde, nunca os valores.
 */
final class StudentDataEditor
{
    /**
     * @param array<string, string> $key Valores atuais da chave primária.
     * @param array<string, string> $values Coluna => novo valor.
     * @param array<string, string> $nulls Colunas marcadas "NULL".
     */
    public static function update(PDO $pdo, AuthenticatedUser $prof, string $db, string $table, array $key, array $values, array $nulls): void
    {
        $t = self::tableOrFail($pdo, $db, $table);
        if ($t['primaryKey'] === []) {
            throw new StudentDataException('Esta tabela não tem chave primária: não dá para alterar uma linha com segurança. Só leitura.');
        }
        $where = [];
        foreach ($t['primaryKey'] as $column) {
            if (!array_key_exists($column, $key)) {
                throw new StudentDataException('Linha não identificada (falta a chave primária).');
            }
            $where[$column] = $key[$column];
        }

        $changes = [];
        foreach (self::editable($t) as $column) {
            if (in_array($column['name'], $t['primaryKey'], true)) {
                continue; // chave não é editável por aqui
            }
            if (isset($nulls[$column['name']]) && $column['nullable']) {
                $changes[$column['name']] = null;
            } elseif (array_key_exists($column['name'], $values)) {
                $changes[$column['name']] = $values[$column['name']];
            }
        }
        if ($changes === []) {
            throw new StudentDataException('Nenhuma coluna editável foi enviada.');
        }

        self::run($pdo, RowEditSql::update($db, $table, $where, $changes));
        AuditLog::record('professor.db_row_updated', 'schema', $db, ['tabela' => $table, 'colunas' => implode(', ', array_keys($changes)), 'chave' => json_encode($where, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * @param array<string, string> $values Coluna => valor (vazio = usa o padrão da coluna).
     * @param array<string, string> $nulls Colunas marcadas "NULL".
     */
    public static function insert(PDO $pdo, AuthenticatedUser $prof, string $db, string $table, array $values, array $nulls): void
    {
        $t = self::tableOrFail($pdo, $db, $table);
        $row = [];
        foreach (self::editable($t) as $column) {
            if (isset($nulls[$column['name']]) && $column['nullable']) {
                $row[$column['name']] = null;
            } elseif (($values[$column['name']] ?? '') !== '') {
                $row[$column['name']] = $values[$column['name']];
            }
        }

        self::run($pdo, RowEditSql::insert($db, $table, $row));
        AuditLog::record('professor.db_row_inserted', 'schema', $db, ['tabela' => $table, 'colunas' => implode(', ', array_keys($row))]);
    }

    /** @return array<string, mixed> */
    private static function tableOrFail(PDO $pdo, string $db, string $table): array
    {
        return StudentDatabases::table($pdo, $db, $table) ?? throw new StudentDataException('Tabela não encontrada neste banco.');
    }

    /**
     * @param array<string, mixed> $table
     * @return list<array<string, mixed>>
     */
    private static function editable(array $table): array
    {
        return array_values(array_filter($table['columns'], static fn (array $c): bool => !$c['binary'] && !str_contains(strtolower($c['extra']), 'generated')));
    }

    /** @param array{0: string, 1: list<scalar|null>} $statement */
    private static function run(PDO $pdo, array $statement): void
    {
        try {
            $pdo->prepare($statement[0])->execute($statement[1]);
        } catch (PDOException $e) {
            // Erro do MySQL do aluno (tipo inválido, FK, duplicado...) é útil pro professor — sem stack.
            throw new StudentDataException('O MySQL recusou: ' . ($e->errorInfo[2] ?? $e->getMessage()), 0, $e);
        }
    }
}
```

- [ ] **Step 3: Rodar** — integração e unitários (exit 0), lint limpo.
- [ ] **Step 4: Commit** — `git add app/Services/StudentDatabases.php app/Services/StudentDataEditor.php app/Services/StudentDataException.php tests/Integration/StudentDatabasesTest.php && git commit -m "Leitura e edição dos bancos dos alunos pela conta do professor"`

---

### Task 4: Telas

**Files:** Create `app/Actions/StudentDatabase/StudentDatabaseAction.php`, `IndexStudentDatabasesAction.php`, `ShowStudentDatabaseAction.php`, `ShowStudentTableAction.php`, `InsertStudentRowAction.php`, `UpdateStudentRowAction.php`; Views `app/Views/professor/databases/index.twig`, `show.twig`, `table.twig`, `_password.twig`; Modify `public/index.php`, `app/Views/partials/nav-links.twig`.

- [ ] **Step 1: Base comum**

`app/Actions/StudentDatabase/StudentDatabaseAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Action;
use App\Core\Auth;
use App\Core\View;
use App\Services\StudentDatabases;
use PDO;

/** Base das telas de bancos dos alunos: escopo do professor e conexão como ele. */
abstract class StudentDatabaseAction extends Action
{
    /** Banco fora do escopo → 404 (não revela que existe). */
    protected function guard(string $db): void
    {
        Auth::requireProfessorOrAdmin();
        if (!StudentDatabases::canAccess(Auth::user(), $db)) {
            http_response_code(404);
            echo View::render('errors/404');
            exit;
        }
    }

    /** Conexão como o professor, ou a tela de confirmar senha (GET) / JSON pedindo a senha (POST). */
    protected function professorConnection(string $db): PDO
    {
        $pdo = StudentDatabases::connectionFor(Auth::user());
        if ($pdo !== null) {
            return $pdo;
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->json(false, 'Confirme sua senha para continuar.', ['needsMysqlPassword' => true]);
        }
        $this->render('professor/databases/_password', ['pageTitle' => 'Confirme sua senha', 'db' => $db]);
        exit;
    }
}
```

- [ ] **Step 2: Actions**

`IndexStudentDatabasesAction.php` (GET `/professor/bancos`):

```php
<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Action;
use App\Core\Auth;
use App\Services\StudentDatabases;
use App\Support\Role;

/** Bancos dos alunos das instituições do professor. GET /professor/bancos. */
final class IndexStudentDatabasesAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();

        $this->render('professor/databases/index', [
            'pageTitle' => 'Bancos dos alunos',
            'groups' => StudentDatabases::visibleTo(Auth::user()),
            'isAdmin' => Auth::user()->role === Role::Admin,
        ]);
    }
}
```

`ShowStudentDatabaseAction.php` (GET `/professor/bancos/{banco}`):

```php
<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Services\StudentDatabases;

/** Estrutura de um banco de aluno: tabelas, colunas, chaves e relações. GET /professor/bancos/{banco}. */
final class ShowStudentDatabaseAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $this->guard($db);
        $tables = StudentDatabases::structure($this->professorConnection($db), $db);

        $relations = [];
        foreach ($tables as $t) {
            foreach ($t['foreignKeys'] as $fk) {
                $relations[] = ['from' => "{$t['name']}.{$fk['column']}", 'to' => "{$fk['refTable']}.{$fk['refColumn']}"];
            }
        }

        $this->render('professor/databases/show', [
            'pageTitle' => $db,
            'db' => $db,
            'owner' => StudentDatabases::ownerName($db),
            'tables' => $tables,
            'relations' => $relations,
        ]);
    }
}
```

`ShowStudentTableAction.php` (GET `/professor/bancos/{banco}/{tabela}?pagina=`):

```php
<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\View;
use App\Services\StudentDatabases;

/** Dados de uma tabela de aluno, com editar/inserir. GET /professor/bancos/{banco}/{tabela}. */
final class ShowStudentTableAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $tableName = rawurldecode((string) ($params['tabela'] ?? ''));
        $this->guard($db);
        $pdo = $this->professorConnection($db);

        $table = StudentDatabases::table($pdo, $db, $tableName);
        if ($table === null) {
            http_response_code(404);
            echo View::render('errors/404');

            return;
        }

        $this->render('professor/databases/table', [
            'pageTitle' => "{$db}.{$tableName}",
            'db' => $db,
            'owner' => StudentDatabases::ownerName($db),
            'table' => $table,
            'data' => StudentDatabases::rows($pdo, $db, $table, (int) ($_GET['pagina'] ?? 1)),
        ]);
    }
}
```

`InsertStudentRowAction.php` (POST `/professor/bancos/{banco}/{tabela}/linhas`):

```php
<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Auth;
use App\Services\StudentDataEditor;
use App\Services\StudentDataException;
use Throwable;

final class InsertStudentRowAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $table = rawurldecode((string) ($params['tabela'] ?? ''));
        $this->guard($db);
        $back = '/professor/bancos/' . rawurlencode($db) . '/' . rawurlencode($table);

        try {
            StudentDataEditor::insert($this->professorConnection($db), Auth::user(), $db, $table, (array) ($_POST['valor'] ?? []), (array) ($_POST['nulo'] ?? []));
            $this->respond(true, 'Linha inserida.', $back);
        } catch (StudentDataException $e) {
            $this->respond(false, $e->getMessage(), $back);
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('inserir a linha', $e), $back);
        }
    }
}
```

`UpdateStudentRowAction.php` (POST `/professor/bancos/{banco}/{tabela}/editar`):

```php
<?php

declare(strict_types=1);

namespace App\Actions\StudentDatabase;

use App\Core\Auth;
use App\Services\StudentDataEditor;
use App\Services\StudentDataException;
use Throwable;

final class UpdateStudentRowAction extends StudentDatabaseAction
{
    public function __invoke(array $params = []): void
    {
        $db = (string) ($params['banco'] ?? '');
        $table = rawurldecode((string) ($params['tabela'] ?? ''));
        $this->guard($db);
        $back = '/professor/bancos/' . rawurlencode($db) . '/' . rawurlencode($table);

        try {
            StudentDataEditor::update($this->professorConnection($db), Auth::user(), $db, $table, (array) ($_POST['chave'] ?? []), (array) ($_POST['valor'] ?? []), (array) ($_POST['nulo'] ?? []));
            $this->respond(true, 'Linha atualizada.', $back);
        } catch (StudentDataException $e) {
            $this->respond(false, $e->getMessage(), $back);
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('alterar a linha', $e), $back);
        }
    }
}
```

- [ ] **Step 3: Views**

`app/Views/professor/databases/_password.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% block content %}
    <div class="mx-auto max-w-md">
        <h1 class="text-2xl font-semibold text-slate-900">Confirme sua senha</h1>
        <p class="mt-2 text-sm text-slate-500">
            Os bancos dos alunos são abertos com a <strong>sua</strong> conta MySQL — é ela que garante que você vê e edita, mas não exclui.
            Confirme a senha da sua conta para continuar (ela fica cifrada só nesta sessão).
        </p>
        <form
            hx-boost="false" method="post" action="/dashboard/sql/confirmar-senha"
            x-data="ajaxForm({ refresh: true })" @submit.prevent="submit" class="card mt-6 space-y-3"
        >
            {{ csrf_field() }}
            <input type="password" name="password" required autocomplete="current-password" class="field-input" placeholder="Sua senha">
            <button :disabled="loading" type="submit" class="btn-primary w-full">Confirmar</button>
        </form>
    </div>
{% endblock %}
```

`app/Views/professor/databases/index.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% block content %}
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-slate-900">Bancos dos alunos</h1>
        <p class="mt-1 text-sm text-slate-500">
            Veja a estrutura e os dados dos bancos dos alunos das suas instituições, e corrija dados para ajudá-los.
            Você pode <strong>inserir e alterar</strong> linhas; <strong>excluir não é possível</strong> — o próprio MySQL bloqueia.
        </p>
    </div>

    {% if groups is empty %}
        <div class="card py-10 text-center text-sm text-slate-500">
            {{ isAdmin ? 'Esta tela é para professores (o admin usa o phpMyAdmin).' : 'Nenhum aluno nas suas instituições ainda.' }}
        </div>
    {% endif %}

    {% for g in groups %}
        <h2 class="mb-3 mt-6 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ g.institution }}</h2>
        <div class="card divide-y divide-slate-100">
            {% for s in g.students %}
                <div class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800">{{ s.name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ s.email }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        {% for db in s.schemas %}
                            <a href="/professor/bancos/{{ db|url_encode }}" class="rounded-full bg-indigo-50 px-3 py-1 font-mono text-xs text-indigo-700 hover:bg-indigo-100">{{ db }}</a>
                        {% else %}
                            <span class="text-xs text-slate-400">nenhum banco ainda</span>
                        {% endfor %}
                    </div>
                </div>
            {% endfor %}
        </div>
    {% endfor %}
{% endblock %}
```

`app/Views/professor/databases/show.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% block content %}
    <div class="mb-6">
        <a href="/professor/bancos" class="text-sm text-slate-500 hover:text-slate-700">← Bancos dos alunos</a>
        <h1 class="mt-2 font-mono text-2xl font-semibold text-slate-900">{{ db }}</h1>
        {% if owner %}<p class="mt-1 text-sm text-slate-500">de {{ owner }} · {{ tables|length }} tabela{{ tables|length == 1 ? '' : 's' }}</p>{% endif %}
    </div>

    {% if relations is not empty %}
        <div class="card mb-6">
            <h2 class="mb-3 text-base font-semibold text-slate-900">Relações</h2>
            <ul class="space-y-1 font-mono text-sm text-slate-700">
                {% for r in relations %}
                    <li>{{ r.from }} <span class="text-indigo-500">→</span> {{ r.to }}</li>
                {% endfor %}
            </ul>
        </div>
    {% endif %}

    {% if tables is empty %}
        <div class="card py-10 text-center text-sm text-slate-500">Este banco ainda não tem tabelas.</div>
    {% endif %}

    <div class="grid gap-6 lg:grid-cols-2">
        {% for t in tables %}
            <div class="card">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <h2 class="truncate font-mono text-base font-semibold text-slate-900">{{ t.name }}</h2>
                    <a href="/professor/bancos/{{ db|url_encode }}/{{ t.name|url_encode }}" class="btn-secondary shrink-0 !px-3 !py-1.5 text-xs">Ver dados</a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="text-slate-400">
                            <tr><th class="py-1 pr-3">Coluna</th><th class="py-1 pr-3">Tipo</th><th class="py-1 pr-3">Nulo</th><th class="py-1">Chave / extra</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono text-slate-700">
                            {% for c in t.columns %}
                                <tr>
                                    <td class="py-1 pr-3">{{ c.name }}</td>
                                    <td class="py-1 pr-3 text-slate-500">{{ c.type }}</td>
                                    <td class="py-1 pr-3">{{ c.nullable ? 'sim' : 'não' }}</td>
                                    <td class="py-1 text-slate-500">
                                        {% if c.name in t.primaryKey %}<span class="rounded bg-amber-50 px-1 text-amber-700">PK</span>{% endif %}
                                        {% for fk in t.foreignKeys|filter(f => f.column == c.name) %}<span class="rounded bg-indigo-50 px-1 text-indigo-700">→ {{ fk.refTable }}.{{ fk.refColumn }}</span>{% endfor %}
                                        {{ c.extra }}{% if c.default is not null %} padrão: {{ c.default }}{% endif %}
                                    </td>
                                </tr>
                            {% endfor %}
                        </tbody>
                    </table>
                </div>
                {% if t.primaryKey is empty %}<p class="mt-2 text-xs text-amber-700">Sem chave primária: os dados desta tabela ficam só leitura.</p>{% endif %}
            </div>
        {% endfor %}
    </div>
{% endblock %}
```

`app/Views/professor/databases/table.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% macro field(c, value) %}
    <label class="block text-xs">
        <span class="font-mono text-slate-600">{{ c.name }} <span class="text-slate-400">{{ c.type }}</span></span>
        <input type="text" name="valor[{{ c.name }}]" value="{{ value }}" class="field-input mt-1 font-mono !py-1.5 text-sm" {{ c.binary ? 'disabled' }}>
        {% if c.nullable and not c.binary %}
            <span class="mt-1 inline-flex items-center gap-1 text-slate-500"><input type="checkbox" name="nulo[{{ c.name }}]" value="1" {{ value is null ? 'checked' }}> NULL</span>
        {% endif %}
    </label>
{% endmacro %}

{% block content %}
    {% import _self as m %}
    {% set base = '/professor/bancos/' ~ db|url_encode ~ '/' ~ table.name|url_encode %}
    {% set editable = table.primaryKey is not empty %}

    <div class="mb-6">
        <a href="/professor/bancos/{{ db|url_encode }}" class="text-sm text-slate-500 hover:text-slate-700">← {{ db }}</a>
        <h1 class="mt-2 font-mono text-2xl font-semibold text-slate-900">{{ table.name }}</h1>
        <p class="mt-1 text-sm text-slate-500">{% if owner %}de {{ owner }} · {% endif %}{{ data.total }} linha{{ data.total == 1 ? '' : 's' }}. Inserir e alterar; excluir não é possível.</p>
        {% if not editable %}<p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Sem chave primária: não dá para identificar uma linha com segurança, então os dados ficam só leitura.</p>{% endif %}
    </div>

    <details class="card mb-6">
        <summary class="cursor-pointer text-base font-semibold text-slate-900">Inserir linha</summary>
        <form hx-boost="false" method="post" action="{{ base }}/linhas" x-data="ajaxForm({ refresh: true })" @submit.prevent="submit" class="mt-4 grid gap-3 sm:grid-cols-2">
            {{ csrf_field() }}
            {% for c in table.columns %}{{ m.field(c, '') }}{% endfor %}
            <p class="text-xs text-slate-500 sm:col-span-2">Campo vazio usa o padrão da coluna (ex.: AUTO_INCREMENT).</p>
            <button :disabled="loading" type="submit" class="btn-primary sm:col-span-2">Inserir</button>
        </form>
    </details>

    <div class="card">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-slate-400">
                    <tr>
                        {% for c in table.columns %}<th class="whitespace-nowrap py-2 pr-4 font-mono">{{ c.name }}{% if c.name in table.primaryKey %} <span class="text-amber-600">PK</span>{% endif %}</th>{% endfor %}
                        {% if editable %}<th></th>{% endif %}
                    </tr>
                </thead>
                {% for row in data.rows %}
                    <tbody x-data="{ open: false }" class="border-t border-slate-100">
                        <tr>
                            {% for c in table.columns %}
                                {% set v = attribute(row, c.name) %}
                                <td class="max-w-[16rem] truncate py-2 pr-4 font-mono text-slate-700" title="{{ v }}">{{ v is null ? 'NULL' : v }}</td>
                            {% endfor %}
                            {% if editable %}<td class="py-2"><button type="button" @click="open = !open" class="btn-secondary !px-3 !py-1 text-xs" x-text="open ? 'Fechar' : 'Editar'"></button></td>{% endif %}
                        </tr>
                        {% if editable %}
                            <tr x-show="open" x-cloak>
                                <td colspan="{{ table.columns|length + 1 }}" class="bg-slate-50 p-4">
                                    <form hx-boost="false" method="post" action="{{ base }}/editar" x-data="ajaxForm({ refresh: true })" @submit.prevent="submit" class="grid gap-3 sm:grid-cols-2">
                                        {{ csrf_field() }}
                                        {% for k in table.primaryKey %}<input type="hidden" name="chave[{{ k }}]" value="{{ attribute(row, k) }}">{% endfor %}
                                        {% for c in table.columns|filter(c => c.name not in table.primaryKey) %}{{ m.field(c, attribute(row, c.name)) }}{% endfor %}
                                        <button :disabled="loading" type="submit" class="btn-primary sm:col-span-2">Salvar alteração</button>
                                    </form>
                                </td>
                            </tr>
                        {% endif %}
                    </tbody>
                {% else %}
                    <tbody><tr><td class="py-6 text-center text-sm text-slate-500" colspan="{{ table.columns|length + 1 }}">Tabela vazia.</td></tr></tbody>
                {% endfor %}
            </table>
        </div>
        {% if data.pages > 1 %}
            <div class="mt-4 flex items-center justify-between text-sm text-slate-500">
                <span>Página {{ data.page }} de {{ data.pages }}</span>
                <div class="flex gap-2">
                    {% if data.page > 1 %}<a href="{{ base }}?pagina={{ data.page - 1 }}" class="btn-secondary !px-3 !py-1.5 text-xs">← Anterior</a>{% endif %}
                    {% if data.page < data.pages %}<a href="{{ base }}?pagina={{ data.page + 1 }}" class="btn-secondary !px-3 !py-1.5 text-xs">Próxima →</a>{% endif %}
                </div>
            </div>
        {% endif %}
    </div>
{% endblock %}
```

- [ ] **Step 4: Rotas e menu**

`public/index.php` (+ `use` das 5 actions de `App\Actions\StudentDatabase`), depois das rotas `/professor/alunos/...`:

```php
$router->get('/professor/bancos', IndexStudentDatabasesAction::class);
$router->get('/professor/bancos/{banco}', ShowStudentDatabaseAction::class);
$router->get('/professor/bancos/{banco}/{tabela}', ShowStudentTableAction::class);
$router->post('/professor/bancos/{banco}/{tabela}/linhas', InsertStudentRowAction::class);
$router->post('/professor/bancos/{banco}/{tabela}/editar', UpdateStudentRowAction::class);
```

`app/Views/partials/nav-links.twig`, grupo "Ensino": acrescentar `{ href: '/professor/bancos', label: 'Bancos dos alunos' },`.

- [ ] **Step 5: Verificar** — suítes verdes; rebuild; como `professor@dblab.local` (local): lista mostra "Aluno Dev"; abrir um banco com tabelas; ver relações; editar e inserir linha (toast, sem reload); conferir na auditoria. Sem senha em cache (login por "lembrar de mim") → tela "Confirme sua senha".

- [ ] **Step 6: Commit** — `git add app/Actions/StudentDatabase app/Views/professor/databases public/index.php app/Views/partials/nav-links.twig && git commit -m "Telas de bancos dos alunos para o professor"`

---

### Task 5: Documentação

- [ ] README (tabela de papéis do professor + linha da rota `/professor/bancos`) e SECURITY.md (seção curta: privilégios exatos do professor, onde são concedidos/revogados, por que DELETE/DROP/ALTER ficam de fora, edição pela conta do próprio professor).
- [ ] Commit — `git add README.md SECURITY.md && git commit -m "Documenta bancos dos alunos para o professor"`

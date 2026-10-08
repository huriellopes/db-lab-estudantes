# Arquivo de dados excluídos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nenhum dado de negócio (usuários, schemas, consultas salvas, diagramas ER) é apagado de verdade: tudo vai para `deleted_models` em lotes, com schemas em quarentena, e o admin restaura ou exclui definitivamente em `/admin/excluidos`, tudo auditado.

**Architecture:** Um serviço central `App\Services\Archiver` é o único que escreve no arquivo; cada Action de exclusão passa a chamá-lo. Schemas vão para um database oculto `_lixeira_s<id>` via `RENAME TABLE` (`App\Services\SchemaQuarantine`); views/triggers/rotinas/eventos têm a definição salva e voltam como uma consulta salva que o aluno roda no console. Regras puras (grafo de lote, mascaramento, checagens de restauração, script de objetos) ficam em `App\Support\*` com testes unitários; o que toca o MySQL tem testes de integração contra o MySQL do `docker compose`.

**Tech Stack:** PHP 8.5 (sem framework), PDO/MySQL 8.0, Twig 3, Alpine.js + Axios + htmx (hx-boost), Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-08-arquivo-de-excluidos-design.md`

## Global Constraints

- Abrangência: só `user`, `schema`, `saved_query`, `er_diagram`. Logs, backups, tokens (`remember_tokens`, `password_reset_tokens`, `rate_limit_hits`) e `audit:prune` continuam como estão.
- Só admin vê, restaura e exclui definitivamente (`Auth::requireAdmin()`).
- Quem exclui não muda: dono (schema/consulta/diagrama), professor (aluno), admin (qualquer não-admin).
- `DROP DATABASE` feito fora da plataforma: só auditado; o registro vai pro arquivo com `meta.removed_outside = true` e não pode ser restaurado.
- Sem privilégio novo pro `appuser` (nada de `SET_USER_ID`); objetos programáveis voltam como consulta salva do aluno, sem `DEFINER`.
- Enquanto o lote existe: `email`, `mysql_login`, `schema_prefix` do usuário e `db_name` do schema ficam reservados.
- Tudo-ou-nada em arquivar e restaurar; DDL do MySQL não é transacional, então o `Archiver` desfaz manualmente.
- Telas sem reload: hx-boost + `ajaxForm({ refresh: true })` (ver README, "Navegação sem reload").
- Comentários e mensagens em português, no estilo do código existente. `vendor/bin/php-cs-fixer fix --dry-run` e `npx prettier --check "resources/**/*.{js,css}"` limpos.

## Antes de começar

Este trabalho começa numa branch própria (ex.: `feat/arquivo-excluidos`), a partir de uma árvore limpa — combine com a pessoa como tratar as mudanças pendentes da branch atual antes do Task 1. Stack local no ar: `docker compose up -d --build` (o MySQL fica em `127.0.0.1:${MYSQL_PORT}`, padrão 3307).

## Mapa de arquivos

**Novos**
- `database/migrations/2026_10_08_000001_create_deleted_models_table.php` — tabela do arquivo.
- `database/migrations/2026_10_08_000002_move_trashed_users_to_deleted_models.php` — migra a lixeira antiga e remove `users.deleted_at`.
- `app/Support/ArchiveGraph.php` — modelos conhecidos, tabela, dependentes, rótulo.
- `app/Support/ArchiveSnapshot.php` — campos sensíveis mascarados para exibição.
- `app/Support/ArchiveRestoreChecks.php` — lista de checagens de conflito de um lote (pura).
- `app/Support/ProgrammableObjects.php` — coleta definições de views/triggers/rotinas/eventos e monta o script de restauração.
- `app/Services/ArchiveException.php` — erro com mensagem segura para mostrar.
- `app/Services/SchemaQuarantine.php` — move schema para quarentena e de volta.
- `app/Services/Archiver.php` — archive / restore / purge.
- `app/Models/DeletedModel.php` — escrita e leitura de `deleted_models`.
- `app/Actions/Admin/IndexDeletedModelsAction.php`, `RestoreDeletedBatchAction.php`, `PurgeDeletedBatchAction.php`, `RedirectTrashAction.php`.
- `app/Views/admin/deleted.twig`.
- Testes: `tests/Unit/ArchiveGraphTest.php`, `ArchiveSnapshotTest.php`, `ArchiveRestoreChecksTest.php`, `ProgrammableObjectsTest.php`; `tests/Integration/SchemaQuarantineTest.php`, `ArchiverTest.php`, `ReservedNamesTest.php`, `DeletedModelListingTest.php`; helpers em `tests/Pest.php`.

**Modificados**
- `app/Support/SqlScriptSplitter.php` (+ teste) — suporte a `DELIMITER`.
- `app/Models/User.php`, `app/Models/Entities/User.php`, `app/Models/SchemaRecord.php`, `app/Models/AdminMetrics.php`, `app/Support/AdminStats.php`, `app/Services/UserManager.php`.
- Actions: `Schema/DestroySchemaAction.php`, `Admin/DestroyAdminSchemaAction.php`, `SavedQuery/DestroySavedQueryAction.php`, `ErDiagram/DestroyErDiagramAction.php`, `Student/DestroyStudentAction.php`, `Admin/DestroyAdminUserAction.php`.
- Removidos: `Admin/TrashAdminUsersAction.php`, `Admin/RestoreAdminUserAction.php`, `app/Views/admin/trash.twig`.
- `public/index.php` (rotas), `app/Views/partials/nav-links.twig`, `app/Views/admin/index.twig`, `app/Views/admin/users.twig`, `composer.json` (script `test:integration`), `README.md`.

---

### Task 1: Tabela `deleted_models`

**Files:**
- Create: `database/migrations/2026_10_08_000001_create_deleted_models_table.php`

**Interfaces:**
- Produces: tabela `deleted_models(id, batch_id, is_root, model, model_id, label, values, meta, deleted_by_id, deleted_by_name, deleted_at)`.

- [ ] **Step 1: Criar a migration**

```php
<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS deleted_models (
                id              BIGINT AUTO_INCREMENT PRIMARY KEY,
                -- UUID do lote: o que foi excluído junto (usuário + schemas + consultas + diagramas)
                -- é restaurado ou excluído definitivamente junto. Ver App\Services\Archiver.
                batch_id        CHAR(36) NOT NULL,
                -- 1 = item que a pessoa mandou excluir; os demais vieram junto por dependência.
                is_root         TINYINT(1) NOT NULL,
                model           VARCHAR(32) NOT NULL,
                -- id original: a restauração reinsere com o mesmo id.
                model_id        INT NOT NULL,
                label           VARCHAR(200) NOT NULL,
                -- Linha completa no momento da exclusão (inclusive password_hash: sem ele o
                -- usuário restaurado não teria senha). A tela mascara campos sensíveis.
                `values`        JSON NOT NULL,
                -- Ex.: database de quarentena, definições de objetos programáveis, removed_outside.
                meta            JSON NULL,
                -- Sem FK de propósito (igual audit_logs): o autor pode ser excluído depois.
                deleted_by_id   INT NULL,
                deleted_by_name VARCHAR(100) NULL,
                deleted_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_batch (batch_id),
                INDEX idx_model (model, model_id),
                INDEX idx_deleted_at (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS deleted_models');
    }
};
```

- [ ] **Step 2: Rodar e conferir**

Run: `docker compose exec app php bin/console.php migrate && docker compose exec app php bin/console.php migrate:status | tail -2`
Expected: `Migrated: 2026_10_08_000001_create_deleted_models_table` e `[x] 2026_10_08_000001_create_deleted_models_table`.

Run: `docker compose exec app php bin/console.php migrate:rollback && docker compose exec app php bin/console.php migrate`
Expected: `Rolled back: ...000001...` e depois `Migrated: ...000001...` (down e up funcionam).

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_10_08_000001_create_deleted_models_table.php
git commit -m "Cria a tabela deleted_models (arquivo de dados excluídos)"
```

---

### Task 2: Regras puras do arquivo (grafo, mascaramento, checagens)

**Files:**
- Create: `app/Support/ArchiveGraph.php`, `app/Support/ArchiveSnapshot.php`, `app/Support/ArchiveRestoreChecks.php`
- Test: `tests/Unit/ArchiveGraphTest.php`, `tests/Unit/ArchiveSnapshotTest.php`, `tests/Unit/ArchiveRestoreChecksTest.php`

**Interfaces:**
- Produces:
  - `ArchiveGraph::MODELS` (`list<string>`), `ArchiveGraph::isKnown(string $model): bool`, `ArchiveGraph::table(string $model): string` (lança `InvalidArgumentException`), `ArchiveGraph::children(string $model): array<string, string>` (modelo filho => coluna FK), `ArchiveGraph::label(string $model, array $row): string`, `ArchiveGraph::typeLabel(string $model): string`.
  - `ArchiveSnapshot::forDisplay(array $values): array<string, scalar|null>`.
  - `ArchiveRestoreChecks::for(array $items): list<array{sql: string, params: list<scalar>, expect: 'absent'|'present', message: string}>`, onde cada `$items[]` é `array{model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>}`.

- [ ] **Step 1: Testes que falham**

`tests/Unit/ArchiveGraphTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\ArchiveGraph;

it('knows the archivable models and their tables', function () {
    expect(ArchiveGraph::MODELS)->toBe(['user', 'schema', 'saved_query', 'er_diagram'])
        ->and(ArchiveGraph::table('user'))->toBe('users')
        ->and(ArchiveGraph::table('schema'))->toBe('schemas_criados')
        ->and(ArchiveGraph::table('saved_query'))->toBe('saved_queries')
        ->and(ArchiveGraph::table('er_diagram'))->toBe('er_diagrams')
        ->and(ArchiveGraph::isKnown('remember_token'))->toBeFalse();
});

it('refuses unknown models instead of building SQL with them', function () {
    ArchiveGraph::table('users; DROP TABLE x');
})->throws(InvalidArgumentException::class);

it('takes a user\'s schemas, saved queries and diagrams along with it', function () {
    expect(ArchiveGraph::children('user'))->toBe(['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id'])
        ->and(ArchiveGraph::children('schema'))->toBe([])
        ->and(ArchiveGraph::children('saved_query'))->toBe([]);
});

it('builds readable labels', function () {
    expect(ArchiveGraph::label('user', ['name' => 'Maria Souza', 'email' => 'maria@x.com']))->toBe('Maria Souza <maria@x.com>')
        ->and(ArchiveGraph::label('schema', ['db_name' => 'maria__bio']))->toBe('maria__bio')
        ->and(ArchiveGraph::label('saved_query', ['title' => 'Top 10']))->toBe('Top 10')
        ->and(ArchiveGraph::label('er_diagram', ['title' => 'Loja']))->toBe('Loja')
        ->and(mb_strlen(ArchiveGraph::label('saved_query', ['title' => str_repeat('a', 300)])))->toBe(200)
        ->and(ArchiveGraph::typeLabel('saved_query'))->toBe('Consulta salva');
});
```

`tests/Unit/ArchiveSnapshotTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\ArchiveSnapshot;

it('masks secrets and shortens long values only for display', function () {
    $display = ArchiveSnapshot::forDisplay([
        'name' => 'Maria',
        'password_hash' => '$2y$10$abc',
        'remember_token' => 'xyz',
        'data' => str_repeat('d', 400),
        'active' => 1,
        'last_login_at' => null,
    ]);

    expect($display['name'])->toBe('Maria')
        ->and($display['password_hash'])->toBe('[omitido]')
        ->and($display['remember_token'])->toBe('[omitido]')
        ->and(mb_strlen((string) $display['data']))->toBe(301)
        ->and($display['active'])->toBe(1)
        ->and($display['last_login_at'])->toBeNull();
});
```

`tests/Unit/ArchiveRestoreChecksTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\ArchiveRestoreChecks;

function archivedItem(string $model, int $id, array $values, array $meta = []): array
{
    return ['model' => $model, 'model_id' => $id, 'label' => "{$model} {$id}", 'values' => $values, 'meta' => $meta];
}

it('checks id, email, login and prefix before restoring a user', function () {
    $checks = ArchiveRestoreChecks::for([
        archivedItem('user', 7, ['email' => 'm@x.com', 'mysql_login' => 'maria', 'schema_prefix' => 'maria']),
    ]);

    expect(array_column($checks, 'sql'))->toBe([
        'SELECT 1 FROM users WHERE id = ?',
        'SELECT 1 FROM users WHERE email = ?',
        'SELECT 1 FROM users WHERE mysql_login = ? OR schema_prefix = ?',
        'SELECT 1 FROM users WHERE mysql_login = ? OR schema_prefix = ?',
    ])
        ->and(array_column($checks, 'expect'))->toBe(['absent', 'absent', 'absent', 'absent'])
        ->and($checks[1]['params'])->toBe(['m@x.com'])
        ->and($checks[2]['params'])->toBe(['maria', 'maria']);
});

it('requires the quarantine to exist and the database name to be free for a schema', function () {
    $checks = ArchiveRestoreChecks::for([
        archivedItem('schema', 3, ['user_id' => 7, 'db_name' => 'maria__bio'], ['quarantine' => '_lixeira_s3']),
    ]);

    $byMessage = array_column($checks, null, 'message');
    expect(array_column($checks, 'expect'))->toBe(['absent', 'absent', 'absent', 'present', 'present'])
        ->and($checks[2]['sql'])->toBe('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?')
        ->and($checks[2]['params'])->toBe(['maria__bio'])
        ->and($checks[3]['params'])->toBe(['_lixeira_s3'])
        ->and($checks[4]['sql'])->toBe('SELECT 1 FROM users WHERE id = ?')
        ->and($checks[4]['params'])->toBe([7])
        ->and(array_keys($byMessage)[4])->toContain('restaure o usuário');
});

it('skips the owner check when the owner comes back in the same batch', function () {
    $checks = ArchiveRestoreChecks::for([
        archivedItem('user', 7, ['email' => 'm@x.com', 'mysql_login' => 'maria', 'schema_prefix' => 'maria']),
        archivedItem('saved_query', 9, ['user_id' => 7, 'title' => 'q']),
    ]);

    $ownerChecks = array_filter($checks, static fn (array $c): bool => $c['expect'] === 'present');
    expect($ownerChecks)->toBe([]);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/pest tests/Unit/ArchiveGraphTest.php tests/Unit/ArchiveSnapshotTest.php tests/Unit/ArchiveRestoreChecksTest.php`
Expected: FAIL — `Class "App\Support\ArchiveGraph" not found` (e as outras duas).

- [ ] **Step 3: Implementar**

`app/Support/ArchiveGraph.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * O que pode ir pro arquivo de excluídos (deleted_models) e o que vai junto — ver
 * App\Services\Archiver. Lista fechada de propósito: model e tabela entram em SQL montado
 * dinamicamente, então nada fora daqui é aceito.
 */
final class ArchiveGraph
{
    public const MODELS = ['user', 'schema', 'saved_query', 'er_diagram'];

    private const TABLES = [
        'user' => 'users',
        'schema' => 'schemas_criados',
        'saved_query' => 'saved_queries',
        'er_diagram' => 'er_diagrams',
    ];

    /** Modelo => [modelo filho => coluna FK no filho]. */
    private const CHILDREN = [
        'user' => ['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id'],
    ];

    private const TYPE_LABELS = [
        'user' => 'Usuário',
        'schema' => 'Schema',
        'saved_query' => 'Consulta salva',
        'er_diagram' => 'Diagrama ER',
    ];

    private const MAX_LABEL = 200;

    public static function isKnown(string $model): bool
    {
        return isset(self::TABLES[$model]);
    }

    public static function table(string $model): string
    {
        return self::TABLES[$model] ?? throw new InvalidArgumentException("Modelo desconhecido no arquivo: {$model}");
    }

    /** @return array<string, string> */
    public static function children(string $model): array
    {
        self::table($model);

        return self::CHILDREN[$model] ?? [];
    }

    /** @param array<string, mixed> $row */
    public static function label(string $model, array $row): string
    {
        $label = match ($model) {
            'user' => sprintf('%s <%s>', $row['name'] ?? '?', $row['email'] ?? '?'),
            'schema' => (string) ($row['db_name'] ?? '?'),
            'saved_query', 'er_diagram' => (string) ($row['title'] ?? '?'),
            default => throw new InvalidArgumentException("Modelo desconhecido no arquivo: {$model}"),
        };

        return mb_substr($label, 0, self::MAX_LABEL);
    }

    public static function typeLabel(string $model): string
    {
        return self::TYPE_LABELS[$model] ?? $model;
    }
}
```

`app/Support/ArchiveSnapshot.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Versão de exibição de uma linha arquivada (tela /admin/excluidos). O arquivo em si guarda
 * a linha inteira — inclusive password_hash, senão restaurar um usuário o deixaria sem senha —
 * mas a tela nunca mostra segredo nem texto gigante (diagrama ER, SQL).
 */
final class ArchiveSnapshot
{
    private const SECRET_KEYS = '/pass|token|secret|hash/i';
    private const MAX_CHARS = 300;

    /**
     * @param array<string, mixed> $values
     * @return array<string, scalar|null>
     */
    public static function forDisplay(array $values): array
    {
        $display = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::SECRET_KEYS, $key) === 1) {
                $display[$key] = '[omitido]';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
            }
            $display[$key] = is_string($value) && mb_strlen($value) > self::MAX_CHARS
                ? mb_substr($value, 0, self::MAX_CHARS) . '…'
                : $value;
        }

        return $display;
    }
}
```

`app/Support/ArchiveRestoreChecks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * O que precisa ser verdade pra restaurar um lote do arquivo, como uma lista de consultas
 * "SELECT 1 ..." — o App\Services\Archiver roda cada uma e junta as mensagens das que
 * falharem. Separado em função pura pra dar pra testar sem banco. Ordem estável (a tela
 * mostra as mensagens nessa ordem).
 */
final class ArchiveRestoreChecks
{
    /**
     * @param list<array{model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>}> $items
     * @return list<array{sql: string, params: list<scalar>, expect: string, message: string}>
     */
    public static function for(array $items): array
    {
        $usersInBatch = [];
        foreach ($items as $item) {
            if ($item['model'] === 'user') {
                $usersInBatch[] = $item['model_id'];
            }
        }

        $checks = [];
        foreach ($items as $item) {
            $table = ArchiveGraph::table($item['model']);
            $v = $item['values'];
            $checks[] = self::absent("SELECT 1 FROM {$table} WHERE id = ?", [$item['model_id']], "{$item['label']}: o id {$item['model_id']} já está em uso.");

            if ($item['model'] === 'user') {
                $checks[] = self::absent('SELECT 1 FROM users WHERE email = ?', [(string) $v['email']], "O e-mail {$v['email']} já pertence a outra conta.");
                $checks[] = self::absent('SELECT 1 FROM users WHERE mysql_login = ? OR schema_prefix = ?', [(string) $v['mysql_login'], (string) $v['mysql_login']], "O username {$v['mysql_login']} já pertence a outra conta.");
                $checks[] = self::absent('SELECT 1 FROM users WHERE mysql_login = ? OR schema_prefix = ?', [(string) $v['schema_prefix'], (string) $v['schema_prefix']], "O prefixo de schema {$v['schema_prefix']} já pertence a outra conta.");
            }

            if ($item['model'] === 'schema') {
                $checks[] = self::absent('SELECT 1 FROM schemas_criados WHERE db_name = ?', [(string) $v['db_name']], "Já existe um schema chamado {$v['db_name']} registrado.");
                $checks[] = self::absent('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [(string) $v['db_name']], "Já existe um database {$v['db_name']} no MySQL (provavelmente recriado pelo console).");
                $checks[] = [
                    'sql' => 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                    'params' => [(string) ($item['meta']['quarantine'] ?? '')],
                    'expect' => 'present',
                    'message' => "A quarentena de {$v['db_name']} não existe mais — não há dados para restaurar.",
                ];
            }

            $ownerId = isset($v['user_id']) ? (int) $v['user_id'] : null;
            if ($ownerId !== null && !in_array($ownerId, $usersInBatch, true)) {
                $checks[] = [
                    'sql' => 'SELECT 1 FROM users WHERE id = ?',
                    'params' => [$ownerId],
                    'expect' => 'present',
                    'message' => "{$item['label']}: o dono (usuário #{$ownerId}) também está excluído — restaure o usuário primeiro.",
                ];
            }
        }

        return $checks;
    }

    /**
     * @param list<scalar> $params
     * @return array{sql: string, params: list<scalar>, expect: string, message: string}
     */
    private static function absent(string $sql, array $params, string $message): array
    {
        return ['sql' => $sql, 'params' => $params, 'expect' => 'absent', 'message' => $message];
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `vendor/bin/pest tests/Unit/ArchiveGraphTest.php tests/Unit/ArchiveSnapshotTest.php tests/Unit/ArchiveRestoreChecksTest.php`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/ArchiveGraph.php app/Support/ArchiveSnapshot.php app/Support/ArchiveRestoreChecks.php tests/Unit/ArchiveGraphTest.php tests/Unit/ArchiveSnapshotTest.php tests/Unit/ArchiveRestoreChecksTest.php
git commit -m "Regras puras do arquivo de excluídos: lote, mascaramento e checagens de restauração"
```

---

### Task 3: `DELIMITER` no console SQL + script de objetos programáveis

**Files:**
- Modify: `app/Support/SqlScriptSplitter.php`
- Create: `app/Support/ProgrammableObjects.php`
- Test: `tests/Unit/SqlScriptSplitterTest.php` (adicionar casos), `tests/Unit/ProgrammableObjectsTest.php`

**Interfaces:**
- Produces:
  - `SqlScriptSplitter::split()` passa a aceitar linhas `DELIMITER <x>` (convenção do cliente `mysql`).
  - `ProgrammableObjects::collect(PDO $pdo, string $dbName): list<array{type: string, name: string, sql: string}>` (ordem de recriação: VIEW, FUNCTION, PROCEDURE, TRIGGER, EVENT).
  - `ProgrammableObjects::stripDefiner(string $sql): string`.
  - `ProgrammableObjects::restoreScript(list<string> $sqls): string`.

- [ ] **Step 1: Testes que falham**

Adicionar ao fim de `tests/Unit/SqlScriptSplitterTest.php`:

```php
it('honours DELIMITER lines so routine bodies with semicolons stay whole', function () {
    $script = "DELIMITER \$\$\nCREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; SET NEW.y = 2; END\$\$\nDELIMITER ;\nSELECT 1;";

    expect(SqlScriptSplitter::split($script))->toBe([
        'CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; SET NEW.y = 2; END',
        'SELECT 1',
    ]);
});

it('does not treat DELIMITER inside a string or mid-line as a command', function () {
    expect(SqlScriptSplitter::split("SELECT 'DELIMITER \$\$'; SELECT 2"))->toBe(["SELECT 'DELIMITER \$\$'", 'SELECT 2']);
});
```

`tests/Unit/ProgrammableObjectsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\ProgrammableObjects;
use App\Support\SqlScriptSplitter;

it('removes the DEFINER clause so the object is recreated as whoever runs it', function () {
    expect(ProgrammableObjects::stripDefiner('CREATE DEFINER=`maria`@`%` TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x = 1'))
        ->toBe('CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x = 1')
        ->and(ProgrammableObjects::stripDefiner("CREATE ALGORITHM=UNDEFINED DEFINER='maria'@'%' SQL SECURITY DEFINER VIEW `v` AS select 1"))
        ->toBe('CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1');
});

it('builds a script the console splits back into one statement per object', function () {
    $script = ProgrammableObjects::restoreScript([
        'CREATE DEFINER=`maria`@`%` VIEW `v` AS select 1 AS `a`',
        'CREATE DEFINER=`maria`@`%` TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; END',
    ]);

    expect(SqlScriptSplitter::split($script))->toBe([
        'CREATE VIEW `v` AS select 1 AS `a`',
        'CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; END',
    ]);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/pest tests/Unit/SqlScriptSplitterTest.php tests/Unit/ProgrammableObjectsTest.php`
Expected: FAIL — o caso com `DELIMITER` devolve o corpo do trigger quebrado em vários pedaços; `Class "App\Support\ProgrammableObjects" not found`.

- [ ] **Step 3: Implementar `DELIMITER` no splitter**

Em `app/Support/SqlScriptSplitter.php`, acrescentar ao docblock da classe o parágrafo:

```php
 * Entende `DELIMITER <x>` no começo de uma linha, como o cliente `mysql`: é como um script
 * de restauração com trigger/procedure (BEGIN ... ; ... END) chega inteiro no console —
 * ver App\Support\ProgrammableObjects::restoreScript().
```

Dentro de `split()`, logo depois de `$i = 0;`, adicionar `$delimiter = ';';`. Depois do bloco que trata `$quote !== null` (antes do `if ($char === "'" ...`), inserir:

```php
            $atLineStart = $i === 0 || $script[$i - 1] === "\n";
            if ($atLineStart && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(\r?\n|$)/Ai', $script, $m, 0, $i) === 1) {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                    $current = '';
                }
                $delimiter = $m[1];
                $i += strlen($m[0]);
                continue;
            }
```

E trocar o bloco `if ($char === ';') { ... }` por:

```php
            if (substr_compare($script, $delimiter, $i, strlen($delimiter)) === 0) {
                $statements[] = trim($current);
                $current = '';
                $i += strlen($delimiter);
                continue;
            }
```

- [ ] **Step 4: Implementar `ProgrammableObjects`**

`app/Support/ProgrammableObjects.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Views, triggers, rotinas e eventos de um schema que vai pra quarentena (ver
 * App\Services\SchemaQuarantine). RENAME TABLE entre databases não leva nada disso (e
 * falha com trigger, erro 1435), e recriar com o aluno como DEFINER exigiria SET_USER_ID pro
 * appuser — privilégio que ele não tem de propósito. Então as definições viram um script que
 * o próprio aluno roda no console, sem DEFINER: o objeto nasce com ele como dono.
 */
final class ProgrammableObjects
{
    /** Ordem de recriação: view antes de rotina que a usa, trigger depois das tabelas. */
    private const ORDER = ['VIEW', 'FUNCTION', 'PROCEDURE', 'TRIGGER', 'EVENT'];

    /** @return list<array{type: string, name: string, sql: string}> */
    public static function collect(PDO $pdo, string $dbName): array
    {
        $found = [];

        $names = static function (string $sql) use ($pdo, $dbName): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$dbName]);

            return $stmt->fetchAll(PDO::FETCH_NUM);
        };

        foreach ($names('SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME') as [$name]) {
            $found[] = ['VIEW', $name, 'SHOW CREATE VIEW', 'Create View'];
        }
        foreach ($names('SELECT ROUTINE_TYPE, ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ? ORDER BY ROUTINE_NAME') as [$type, $name]) {
            $found[] = [$type, $name, "SHOW CREATE {$type}", $type === 'FUNCTION' ? 'Create Function' : 'Create Procedure'];
        }
        foreach ($names('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY TRIGGER_NAME') as [$name]) {
            $found[] = ['TRIGGER', $name, 'SHOW CREATE TRIGGER', 'SQL Original Statement'];
        }
        foreach ($names('SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ? ORDER BY EVENT_NAME') as [$name]) {
            $found[] = ['EVENT', $name, 'SHOW CREATE EVENT', 'Create Event'];
        }

        $objects = [];
        foreach ($found as [$type, $name, $show, $column]) {
            $row = $pdo->query("{$show} `{$dbName}`.`" . str_replace('`', '``', $name) . '`')->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && ($row[$column] ?? null) !== null) {
                $objects[] = ['type' => $type, 'name' => $name, 'sql' => (string) $row[$column]];
            }
        }

        usort($objects, static fn (array $a, array $b): int => array_search($a['type'], self::ORDER, true) <=> array_search($b['type'], self::ORDER, true));

        return $objects;
    }

    public static function stripDefiner(string $sql): string
    {
        return (string) preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+?)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1);
    }

    /** @param list<string> $sqls */
    public static function restoreScript(array $sqls): string
    {
        $body = implode("\$\$\n\n", array_map(self::stripDefiner(...), $sqls));

        return "-- Views, triggers, rotinas e eventos deste schema, salvos quando ele foi excluído.\n"
            . "-- Rode este script inteiro no console (com este schema selecionado) para recriá-los.\n"
            . "DELIMITER \$\$\n{$body}\$\$\nDELIMITER ;\n";
    }
}
```

- [ ] **Step 5: Rodar e ver passar (e não quebrar o resto)**

Run: `vendor/bin/pest tests/Unit/SqlScriptSplitterTest.php tests/Unit/ProgrammableObjectsTest.php && vendor/bin/pest`
Expected: PASS; suíte inteira verde.

- [ ] **Step 6: Commit**

```bash
git add app/Support/SqlScriptSplitter.php app/Support/ProgrammableObjects.php tests/Unit/SqlScriptSplitterTest.php tests/Unit/ProgrammableObjectsTest.php
git commit -m "Console SQL entende DELIMITER; script de restauração de views/triggers/rotinas"
```

---

### Task 4: Infra de testes de integração (MySQL do docker compose)

**Files:**
- Modify: `tests/Pest.php`, `composer.json`

**Interfaces:**
- Produces (funções globais nos testes):
  - `requiresDatabase(): void` — pula o teste se `INTEGRATION` != `1`; senão aponta `App\Core\Config` pro MySQL do compose e falha com mensagem clara se ele não responder.
  - `integrationUser(App\Support\Role $role = App\Support\Role::Aluno): App\Models\Entities\User` — cria conta real (linha + conta MySQL) com login `it<8 hex>`.
  - `integrationSchema(App\Models\Entities\User $owner, string $label = 'dados'): string` — cria database `<prefix>__<label>` + registro; devolve o nome.
  - `cleanupIntegrationData(): void` — remove só o que os testes geram (login `it` + 8 hex, e-mail `it-…@example.test`): contas MySQL, databases `it<hex>__*`, quarentenas desses lotes e os lotes. Nunca `LIKE 'it%'`, que pegaria contas reais.
- Composer: `composer test:integration`.

- [ ] **Step 1: Helpers em `tests/Pest.php`**

Substituir o bloco de exemplo `function something() { // .. }` por:

```php
use App\Core\Database;
use App\Models\Entities\User as UserEntity;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Services\SchemaProvisioner;
use App\Services\UserManager;
use App\Support\Role;

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
```

> Nota: `mysql.user` só é legível porque o `appuser` tem `SELECT ON *.*` (ver `mysql/init/01-grants.sql`).

- [ ] **Step 2: Script no `composer.json`**

Em `"scripts"`, logo depois de `"test": "pest",`, adicionar:

```json
        "test:integration": "INTEGRATION=1 pest tests/Integration",
```

- [ ] **Step 3: Teste de fumaça (temporário, não commitar)**

Criar `tests/Integration/SmokeTest.php`:

```php
<?php

declare(strict_types=1);

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('creates and cleans up an integration user', function () {
    $user = integrationUser();
    $db = integrationSchema($user);

    expect(App\Models\User::find($user->id))->not->toBeNull()
        ->and(App\Models\SchemaRecord::nameTaken($db))->toBeTrue();
});
```

Run: `composer test:integration`
Expected: `1 passed`.

Run: `vendor/bin/pest tests/Integration`
Expected: `1 skipped` (sem `INTEGRATION=1`).

Depois: `rm tests/Integration/SmokeTest.php`.

- [ ] **Step 4: Commit**

```bash
git add tests/Pest.php composer.json
git commit -m "Infra de testes de integração contra o MySQL do docker compose"
```

---

### Task 5: Quarentena de schemas

**Files:**
- Create: `app/Services/ArchiveException.php`, `app/Services/SchemaQuarantine.php`
- Test: `tests/Integration/SchemaQuarantineTest.php`

**Interfaces:**
- Consumes: `ProgrammableObjects::collect()` (Task 3).
- Produces:
  - `ArchiveException extends RuntimeException` — mensagem segura pra mostrar; propriedade pública `list<string> $orphanedObjects` (definições que se perderiam numa falha).
  - `SchemaQuarantine::nameFor(int $schemaId): string` → `_lixeira_s<id>`.
  - `SchemaQuarantine::move(string $dbName, int $schemaId): array{quarantine: string, objects: list<string>}`.
  - `SchemaQuarantine::restore(string $dbName, string $quarantine, string $ownerLogin): void`.
  - `SchemaQuarantine::purge(string $quarantine): void`.
  - `SchemaQuarantine::exists(string $dbName): bool`.
  - `SchemaQuarantine::sizeBytes(list<string> $dbNames): array<string, int>`.

- [ ] **Step 1: Teste que falha**

`tests/Integration/SchemaQuarantineTest.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\SchemaQuarantine;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('moves tables with data into quarantine and back, saving views and triggers', function () {
    $owner = integrationUser();
    $db = integrationSchema($owner);
    $pdo = Database::connection();
    $pdo->exec("CREATE TABLE `{$db}`.aluno (id INT PRIMARY KEY, nome VARCHAR(50), nota INT DEFAULT 0)");
    $pdo->exec("INSERT INTO `{$db}`.aluno VALUES (1, 'Ana', 9), (2, 'Bia', 7)");
    $pdo->exec("CREATE VIEW `{$db}`.aprovados AS SELECT nome FROM `{$db}`.aluno WHERE nota >= 7");
    $pdo->exec("CREATE TRIGGER `{$db}`.nota_padrao BEFORE INSERT ON `{$db}`.aluno FOR EACH ROW BEGIN IF NEW.nota IS NULL THEN SET NEW.nota = 0; END IF; END");

    $schemaId = (int) $pdo->query("SELECT id FROM schemas_criados WHERE db_name = '{$db}'")->fetchColumn();
    $moved = SchemaQuarantine::move($db, $schemaId);

    expect($moved['quarantine'])->toBe("_lixeira_s{$schemaId}")
        ->and(SchemaQuarantine::exists($db))->toBeFalse()
        ->and((int) $pdo->query("SELECT COUNT(*) FROM `_lixeira_s{$schemaId}`.aluno")->fetchColumn())->toBe(2)
        ->and($moved['objects'])->toHaveCount(2)
        ->and($moved['objects'][0])->toContain('VIEW')
        ->and($moved['objects'][1])->toContain('TRIGGER');

    SchemaQuarantine::restore($db, $moved['quarantine'], $owner->mysqlLogin);

    expect(SchemaQuarantine::exists($moved['quarantine']))->toBeFalse()
        ->and($pdo->query("SELECT GROUP_CONCAT(nome ORDER BY id) FROM `{$db}`.aluno")->fetchColumn())->toBe('Ana,Bia');

    $grant = $pdo->prepare('SELECT 1 FROM mysql.db WHERE Db = ? AND User = ?');
    $grant->execute([$db, $owner->mysqlLogin]);
    expect($grant->fetch())->not->toBeFalse();
});

it('quarantines an empty schema and purges it', function () {
    $owner = integrationUser();
    $db = integrationSchema($owner, 'vazio');
    $schemaId = (int) Database::connection()->query("SELECT id FROM schemas_criados WHERE db_name = '{$db}'")->fetchColumn();

    $moved = SchemaQuarantine::move($db, $schemaId);
    expect(SchemaQuarantine::exists($moved['quarantine']))->toBeTrue()
        ->and(SchemaQuarantine::sizeBytes([$moved['quarantine']]))->toBe([$moved['quarantine'] => 0]);

    SchemaQuarantine::purge($moved['quarantine']);
    expect(SchemaQuarantine::exists($moved['quarantine']))->toBeFalse();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test:integration -- --filter=SchemaQuarantine`
Expected: FAIL — `Class "App\Services\SchemaQuarantine" not found`.

- [ ] **Step 3: Implementar**

`app/Services/ArchiveException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Falha esperada do arquivo de excluídos (conflito ao restaurar, schema que sumiu...). A
 * mensagem é escrita aqui mesmo e é segura pra mostrar na tela — diferente de um
 * PDOException, que passa por Controller::genericError().
 */
final class ArchiveException extends RuntimeException
{
    /** @param list<string> $orphanedObjects Definições que não puderam ser recriadas (ver SchemaQuarantine::move). */
    public function __construct(string $message, public readonly array $orphanedObjects = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
```

`app/Services/SchemaQuarantine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\ProgrammableObjects;
use PDO;
use Throwable;

/**
 * Quarentena de um schema excluído: as tabelas vão inteiras (dados, índices, FKs) pra um
 * database oculto `_lixeira_s<id do schema>` com um único RENAME TABLE (atômico no MySQL),
 * fora do prefixo do aluno — então ele não enxerga nem mexe. Views, triggers, rotinas e
 * eventos não atravessam database: a definição volta como script (ver ProgrammableObjects).
 *
 * Nomes de database aqui vêm sempre do próprio banco (schemas_criados / deleted_models),
 * nunca de input; mesmo assim só passam se baterem com SAFE_NAME.
 */
final class SchemaQuarantine
{
    private const SAFE_NAME = '/^[A-Za-z0-9_]{1,64}$/';

    public static function nameFor(int $schemaId): string
    {
        return '_lixeira_s' . $schemaId;
    }

    /** @return array{quarantine: string, objects: list<string>} */
    public static function move(string $dbName, int $schemaId): array
    {
        $pdo = Database::connection();
        $quarantine = self::nameFor($schemaId);
        self::assertSafe($dbName, $quarantine);

        $objects = ProgrammableObjects::collect($pdo, $dbName);
        $tables = self::baseTables($pdo, $dbName);

        $pdo->exec("CREATE DATABASE `{$quarantine}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            // Trigger impede RENAME TABLE entre databases (erro 1435); a definição já está em $objects.
            foreach ($objects as $object) {
                if ($object['type'] === 'TRIGGER') {
                    $pdo->exec("DROP TRIGGER `{$dbName}`.`" . str_replace('`', '``', $object['name']) . '`');
                }
            }
            if ($tables !== []) {
                $pdo->exec('RENAME TABLE ' . implode(', ', array_map(
                    static fn (string $t): string => "`{$dbName}`.`{$t}` TO `{$quarantine}`.`{$t}`",
                    $tables,
                )));
            }
        } catch (Throwable $e) {
            // RENAME TABLE é atômico: se falhou, nada foi movido e a quarentena está vazia.
            $pdo->exec("DROP DATABASE IF EXISTS `{$quarantine}`");
            $dropped = array_values(array_map(
                static fn (array $o): string => $o['sql'],
                array_filter($objects, static fn (array $o): bool => $o['type'] === 'TRIGGER'),
            ));
            throw new ArchiveException("Não foi possível mover o schema {$dbName} para a quarentena.", $dropped, $e);
        }

        // Leva junto views/rotinas/eventos que sobraram (já salvos em $objects).
        $pdo->exec("DROP DATABASE `{$dbName}`");

        return ['quarantine' => $quarantine, 'objects' => array_column($objects, 'sql')];
    }

    public static function restore(string $dbName, string $quarantine, string $ownerLogin): void
    {
        $pdo = Database::connection();
        self::assertSafe($dbName, $quarantine);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $tables = self::baseTables($pdo, $quarantine);
        if ($tables !== []) {
            $pdo->exec('RENAME TABLE ' . implode(', ', array_map(
                static fn (string $t): string => "`{$quarantine}`.`{$t}` TO `{$dbName}`.`{$t}`",
                $tables,
            )));
        }
        $pdo->exec("DROP DATABASE `{$quarantine}`");
        $pdo->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO " . $pdo->quote($ownerLogin) . "@'%'");
    }

    public static function purge(string $quarantine): void
    {
        self::assertSafe($quarantine);
        Database::connection()->exec("DROP DATABASE IF EXISTS `{$quarantine}`");
    }

    public static function exists(string $dbName): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->execute([$dbName]);

        return $stmt->fetch() !== false;
    }

    /**
     * @param list<string> $dbNames
     * @return array<string, int>
     */
    public static function sizeBytes(array $dbNames): array
    {
        $sizes = array_fill_keys($dbNames, 0);
        if ($dbNames === []) {
            return $sizes;
        }
        $stmt = Database::connection()->prepare(
            'SELECT TABLE_SCHEMA, COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA IN (' . implode(',', array_fill(0, count($dbNames), '?')) . ') GROUP BY TABLE_SCHEMA',
        );
        $stmt->execute($dbNames);
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $db => $bytes) {
            $sizes[$db] = (int) $bytes;
        }

        return $sizes;
    }

    /** @return list<string> */
    private static function baseTables(PDO $pdo, string $dbName): array
    {
        $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $stmt->execute([$dbName]);

        return array_map(static fn (string $t): string => str_replace('`', '``', $t), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function assertSafe(string ...$names): void
    {
        foreach ($names as $name) {
            if (preg_match(self::SAFE_NAME, $name) !== 1) {
                throw new ArchiveException("Nome de database inesperado: {$name}");
            }
        }
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `composer test:integration -- --filter=SchemaQuarantine`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/ArchiveException.php app/Services/SchemaQuarantine.php tests/Integration/SchemaQuarantineTest.php
git commit -m "Quarentena de schemas: RENAME TABLE para _lixeira_s<id> e de volta"
```

---

### Task 6: Repositório `DeletedModel` e serviço `Archiver`

**Files:**
- Create: `app/Models/DeletedModel.php`, `app/Services/Archiver.php`
- Test: `tests/Integration/ArchiverTest.php`

**Interfaces:**
- Consumes: `ArchiveGraph`, `ArchiveRestoreChecks` (Task 2), `ProgrammableObjects::restoreScript` (Task 3), `SchemaQuarantine`, `ArchiveException` (Task 5), `App\Models\AuditLog::record`, `App\Models\SavedQuery::create`, `App\Models\RememberToken::revokeAllFor`, `SchemaProvisioner::lockMysqlAccount/unlockMysqlAccount/dropMysqlAccount`.
- Produces:
  - `DeletedModel::insert(string $batchId, bool $isRoot, string $model, int $modelId, string $label, array $values, array $meta, ?array $actor): void` (`$actor` = `array{id: ?int, name: string}`).
  - `DeletedModel::itemsOfBatch(string $batchId): list<array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array, meta: array, deleted_by_name: ?string, deleted_at: string}>` (raiz primeiro).
  - `DeletedModel::deleteBatch(string $batchId): void`.
  - `DeletedModel::countBatches(): int`.
  - `Archiver::ORIGIN_APP = 'app'`, `Archiver::ORIGIN_OUTSIDE = 'outside'`.
  - `Archiver::archive(string $model, int $id, string $auditAction, array $auditMeta = [], string $origin = self::ORIGIN_APP, ?array $actor = null): string` (devolve `batch_id`; lança `ArchiveException`).
  - `Archiver::conflicts(string $batchId): list<string>`.
  - `Archiver::restore(string $batchId): void` (lança `ArchiveException`).
  - `Archiver::purge(string $batchId): void` (lança `ArchiveException`).
  - Auditoria: `<auditAction>` (com `batch`), `archive.restored`, `archive.restore_failed`, `archive.purged`, `archive.failed`.

- [ ] **Step 1: Teste que falha**

`tests/Integration/ArchiverTest.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\DeletedModel;
use App\Models\SavedQuery;
use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\ArchiveException;
use App\Services\Archiver;
use App\Services\SchemaQuarantine;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

function accountLocked(string $login): bool
{
    $stmt = Database::connection()->prepare("SELECT account_locked FROM mysql.user WHERE User = ? AND Host = '%'");
    $stmt->execute([$login]);

    return $stmt->fetchColumn() === 'Y';
}

function auditCount(string $action, string $batchId): int
{
    $stmt = Database::connection()->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ? AND JSON_UNQUOTE(JSON_EXTRACT(meta, '$.batch')) = ?");
    $stmt->execute([$action, $batchId]);

    return (int) $stmt->fetchColumn();
}

it('archives a user with schema, query and diagram, restores everything and audits both', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    Database::connection()->exec("CREATE TABLE `{$db}`.t (id INT PRIMARY KEY, v VARCHAR(10))");
    Database::connection()->exec("INSERT INTO `{$db}`.t VALUES (1, 'um')");
    Database::connection()->exec("CREATE TRIGGER `{$db}`.trg BEFORE INSERT ON `{$db}`.t FOR EACH ROW SET NEW.v = UPPER(NEW.v)");
    SavedQuery::create($user->id, 'q-it consulta', $db, 'SELECT 1');

    $batch = Archiver::archive('user', $user->id, 'user.deleted');

    $items = DeletedModel::itemsOfBatch($batch);
    expect(array_column($items, 'model'))->toBe(['user', 'schema', 'saved_query'])
        ->and($items[0]['is_root'])->toBeTrue()
        ->and(User::find($user->id))->toBeNull()
        ->and(SchemaRecord::nameTaken($db))->toBeFalse()
        ->and(SchemaQuarantine::exists($db))->toBeFalse()
        ->and(accountLocked($user->mysqlLogin))->toBeTrue()
        ->and(auditCount('user.deleted', $batch))->toBe(1);

    Archiver::restore($batch);

    $restored = User::find($user->id);
    expect($restored?->email)->toBe($user->email)
        ->and($restored?->passwordHash)->toBe($user->passwordHash)
        ->and(SchemaRecord::nameTaken($db))->toBeTrue()
        ->and(Database::connection()->query("SELECT v FROM `{$db}`.t WHERE id = 1")->fetchColumn())->toBe('um')
        ->and(accountLocked($user->mysqlLogin))->toBeFalse()
        ->and(DeletedModel::itemsOfBatch($batch))->toBe([])
        ->and(auditCount('archive.restored', $batch))->toBe(1);

    $titles = array_map(static fn ($q) => $q->title, SavedQuery::allForUser($user->id));
    expect($titles)->toContain('q-it consulta')
        ->and($titles)->toContain("Restaurar objetos de {$db}");
});

it('refuses to restore when the e-mail was taken meanwhile, without changing anything', function () {
    $user = integrationUser();
    $batch = Archiver::archive('user', $user->id, 'user.deleted');
    Database::connection()->prepare('INSERT INTO users (name, email, password_hash, role, mysql_login, schema_prefix) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute(['Integração intrusa', $user->email, 'x', 'aluno', 'itzzzzzzzz', 'itzzzzzzzz']);

    expect(Archiver::conflicts($batch))->toContain("O e-mail {$user->email} já pertence a outra conta.");
    expect(fn () => Archiver::restore($batch))->toThrow(ArchiveException::class);
    expect(DeletedModel::itemsOfBatch($batch))->toHaveCount(1)
        ->and(auditCount('archive.restore_failed', $batch))->toBe(1);
});

it('purges a batch: quarantine and MySQL account are gone, the audit stays', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    $batch = Archiver::archive('user', $user->id, 'user.deleted');
    $quarantine = DeletedModel::itemsOfBatch($batch)[1]['meta']['quarantine'];

    Archiver::purge($batch);

    $accounts = Database::connection()->prepare('SELECT COUNT(*) FROM mysql.user WHERE User = ?');
    $accounts->execute([$user->mysqlLogin]);
    expect(SchemaQuarantine::exists($quarantine))->toBeFalse()
        ->and((int) $accounts->fetchColumn())->toBe(0)
        ->and(DeletedModel::itemsOfBatch($batch))->toBe([])
        ->and(auditCount('archive.purged', $batch))->toBe(1)
        ->and(auditCount('user.deleted', $batch))->toBe(1);
});

it('archives a schema removed outside the platform without data and refuses to restore it', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    Database::connection()->exec("DROP DATABASE `{$db}`");
    $schemaId = (int) Database::connection()->query("SELECT id FROM schemas_criados WHERE db_name = '{$db}'")->fetchColumn();

    $batch = Archiver::archive('schema', $schemaId, 'schema.removed_outside', origin: Archiver::ORIGIN_OUTSIDE);

    expect(DeletedModel::itemsOfBatch($batch)[0]['meta'])->toBe(['removed_outside' => true]);
    expect(fn () => Archiver::restore($batch))->toThrow(ArchiveException::class, 'removido fora da plataforma');
});

it('archives a single saved query and brings it back', function () {
    $user = integrationUser();
    SavedQuery::create($user->id, 'q-it sozinha', null, 'SELECT 2');
    $id = SavedQuery::allForUser($user->id)[0]->id;

    $batch = Archiver::archive('saved_query', $id, 'saved_query.deleted');
    expect(SavedQuery::findOwned($id, $user->id))->toBeNull();

    Archiver::restore($batch);
    expect(SavedQuery::findOwned($id, $user->id)?->title)->toBe('q-it sozinha');
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test:integration -- --filter=Archiver`
Expected: FAIL — `Class "App\Services\Archiver" not found`.

- [ ] **Step 3: Implementar `DeletedModel`**

`app/Models/DeletedModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Tabela deleted_models — o arquivo de dados excluídos. Só App\Services\Archiver escreve
 * aqui; a tela /admin/excluidos só lê (ver paginateBatches, Task 10).
 */
final class DeletedModel
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $meta
     * @param ?array{id: ?int, name: string} $actor
     */
    public static function insert(string $batchId, bool $isRoot, string $model, int $modelId, string $label, array $values, array $meta, ?array $actor): void
    {
        Database::connection()->prepare(
            'INSERT INTO deleted_models (batch_id, is_root, model, model_id, label, `values`, meta, deleted_by_id, deleted_by_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([
            $batchId,
            $isRoot ? 1 : 0,
            $model,
            $modelId,
            $label,
            json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $actor['id'] ?? null,
            $actor['name'] ?? null,
        ]);
    }

    /** @return list<array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>, deleted_by_name: ?string, deleted_at: string}> */
    public static function itemsOfBatch(string $batchId): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM deleted_models WHERE batch_id = ? ORDER BY is_root DESC, id');
        $stmt->execute([$batchId]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public static function deleteBatch(string $batchId): void
    {
        Database::connection()->prepare('DELETE FROM deleted_models WHERE batch_id = ?')->execute([$batchId]);
    }

    public static function countBatches(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM deleted_models WHERE is_root = 1')->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, batch_id: string, is_root: bool, model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>, deleted_by_name: ?string, deleted_at: string}
     */
    public static function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'batch_id' => (string) $row['batch_id'],
            'is_root' => (bool) $row['is_root'],
            'model' => (string) $row['model'],
            'model_id' => (int) $row['model_id'],
            'label' => (string) $row['label'],
            'values' => json_decode((string) $row['values'], true) ?: [],
            'meta' => $row['meta'] !== null ? (json_decode((string) $row['meta'], true) ?: []) : [],
            'deleted_by_name' => $row['deleted_by_name'] !== null ? (string) $row['deleted_by_name'] : null,
            'deleted_at' => (string) $row['deleted_at'],
        ];
    }
}
```

- [ ] **Step 4: Implementar `Archiver`**

`app/Services/Archiver.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Models\AuditLog;
use App\Models\DeletedModel;
use App\Models\RememberToken;
use App\Models\SavedQuery;
use App\Support\ArchiveGraph;
use App\Support\ArchiveRestoreChecks;
use App\Support\ErrorLogger;
use App\Support\ProgrammableObjects;
use PDO;
use Throwable;

/**
 * Único caminho pra "excluir" dado de negócio: copia pra deleted_models em lote (item + o que
 * depende dele), põe schemas em quarentena, bloqueia a conta MySQL de usuário e só então apaga
 * as linhas originais. restore() faz o caminho inverso; purge() é a exclusão de verdade.
 *
 * DDL do MySQL (RENAME TABLE, ALTER USER, DROP DATABASE) faz commit implícito e não volta com
 * rollBack(), então cada passo de DDL já feito entra numa lista de desfazer, executada se algo
 * falhar depois — tudo-ou-nada na prática.
 */
final class Archiver
{
    public const ORIGIN_APP = 'app';
    /** Registro de schema cujo database alguém apagou por fora (console, phpMyAdmin, SGBD). */
    public const ORIGIN_OUTSIDE = 'outside';

    /**
     * @param array<string, mixed> $auditMeta
     * @param ?array{id: ?int, name: string} $actor Padrão: quem está logado.
     */
    public static function archive(string $model, int $id, string $auditAction, array $auditMeta = [], string $origin = self::ORIGIN_APP, ?array $actor = null): string
    {
        $pdo = Database::connection();
        $actor ??= self::currentActor();

        $root = self::fetchRow($pdo, $model, $id) ?? throw new ArchiveException('Registro não encontrado.');
        $items = [['model' => $model, 'row' => $root, 'is_root' => true]];
        foreach (ArchiveGraph::children($model) as $childModel => $fk) {
            $stmt = $pdo->prepare('SELECT * FROM ' . ArchiveGraph::table($childModel) . " WHERE {$fk} = ? ORDER BY id");
            $stmt->execute([$id]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $items[] = ['model' => $childModel, 'row' => $row, 'is_root' => false];
            }
        }

        $batchId = self::uuid();
        $undo = [];
        $metaById = [];

        try {
            foreach ($items as $i => $item) {
                if ($item['model'] !== 'schema') {
                    continue;
                }
                if ($origin === self::ORIGIN_OUTSIDE) {
                    $metaById[$i] = ['removed_outside' => true];
                    continue;
                }
                try {
                    $moved = SchemaQuarantine::move((string) $item['row']['db_name'], (int) $item['row']['id']);
                } catch (ArchiveException $e) {
                    // Triggers já removidos antes do RENAME que falhou: viram consulta salva do dono, nada se perde.
                    if ($e->orphanedObjects !== []) {
                        self::saveObjectsScript((int) $item['row']['user_id'], (string) $item['row']['db_name'], $e->orphanedObjects);
                    }
                    throw $e;
                }
                $metaById[$i] = $moved;
                $owner = self::ownerLogin($pdo, (int) $item['row']['user_id'], $items);
                $undo[] = static fn () => SchemaQuarantine::restore((string) $item['row']['db_name'], $moved['quarantine'], $owner);
            }

            if ($model === 'user') {
                SchemaProvisioner::lockMysqlAccount((string) $root['mysql_login']);
                $undo[] = static fn () => SchemaProvisioner::unlockMysqlAccount((string) $root['mysql_login']);
            }

            $pdo->beginTransaction();
            foreach ($items as $i => $item) {
                DeletedModel::insert($batchId, $item['is_root'], $item['model'], (int) $item['row']['id'], ArchiveGraph::label($item['model'], $item['row']), $item['row'], $metaById[$i] ?? [], $actor);
            }
            // Filhos de usuário saem pela FK ON DELETE CASCADE (já estão arquivados acima).
            $pdo->prepare('DELETE FROM ' . ArchiveGraph::table($model) . ' WHERE id = ?')->execute([$id]);
            if ($model === 'user') {
                // Operacional, sem FK: não vai pro arquivo (ver Global Constraints da spec).
                $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ?')->execute([$id]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::runUndo($undo);
            AuditLog::record('archive.failed', $model, $id, ['batch' => $batchId, 'erro' => $e->getMessage()]);
            throw $e instanceof ArchiveException ? $e : new ArchiveException('Não foi possível excluir agora — nada foi alterado.', [], $e);
        }

        if ($model === 'user') {
            RememberToken::revokeAllFor($id);
        }

        AuditLog::record($auditAction, $model, $id, ['batch' => $batchId, 'itens' => count($items)] + $auditMeta);

        return $batchId;
    }

    /** @return list<string> Mensagens do que impede restaurar (vazio = pode restaurar). */
    public static function conflicts(string $batchId): array
    {
        $pdo = Database::connection();
        $messages = [];
        foreach (ArchiveRestoreChecks::for(DeletedModel::itemsOfBatch($batchId)) as $check) {
            $stmt = $pdo->prepare($check['sql']);
            $stmt->execute($check['params']);
            $found = $stmt->fetch() !== false;
            if ($found !== ($check['expect'] === 'present')) {
                $messages[] = $check['message'];
            }
        }

        return $messages;
    }

    public static function restore(string $batchId): void
    {
        $pdo = Database::connection();
        $items = DeletedModel::itemsOfBatch($batchId);
        if ($items === []) {
            throw new ArchiveException('Lote não encontrado no arquivo.');
        }
        $root = $items[0];

        foreach ($items as $item) {
            if (($item['meta']['removed_outside'] ?? false) === true) {
                throw new ArchiveException('Este schema foi removido fora da plataforma: não há dados para restaurar. Só é possível excluir definitivamente.');
            }
        }

        $conflicts = self::conflicts($batchId);
        if ($conflicts !== []) {
            AuditLog::record('archive.restore_failed', $root['model'], $root['model_id'], ['batch' => $batchId, 'motivo' => implode(' ', $conflicts)]);
            throw new ArchiveException('Não dá para restaurar: ' . implode(' ', $conflicts));
        }

        $undo = [];
        try {
            $pdo->beginTransaction();
            foreach ($items as $item) {
                self::insertRow($pdo, ArchiveGraph::table($item['model']), $item['values']);
            }
            $pdo->commit();
            $undo[] = static function () use ($pdo, $items): void {
                foreach (array_reverse($items) as $item) {
                    $pdo->prepare('DELETE FROM ' . ArchiveGraph::table($item['model']) . ' WHERE id = ?')->execute([$item['model_id']]);
                }
            };

            foreach ($items as $item) {
                if ($item['model'] !== 'schema') {
                    continue;
                }
                $login = self::currentLogin($pdo, (int) $item['values']['user_id']);
                SchemaQuarantine::restore((string) $item['values']['db_name'], (string) $item['meta']['quarantine'], $login);
                $undo[] = static fn () => SchemaQuarantine::move((string) $item['values']['db_name'], $item['model_id']);
            }

            if ($root['model'] === 'user') {
                SchemaProvisioner::unlockMysqlAccount((string) $root['values']['mysql_login']);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::runUndo($undo);
            AuditLog::record('archive.restore_failed', $root['model'], $root['model_id'], ['batch' => $batchId, 'motivo' => $e->getMessage()]);
            throw new ArchiveException('Não foi possível restaurar agora — nada foi alterado.', [], $e);
        }

        foreach ($items as $item) {
            if ($item['model'] === 'schema' && ($item['meta']['objects'] ?? []) !== []) {
                self::saveObjectsScript((int) $item['values']['user_id'], (string) $item['values']['db_name'], $item['meta']['objects']);
            }
        }

        DeletedModel::deleteBatch($batchId);
        AuditLog::record('archive.restored', $root['model'], $root['model_id'], ['batch' => $batchId, 'itens' => count($items), 'rotulo' => $root['label']]);
    }

    public static function purge(string $batchId): void
    {
        $items = DeletedModel::itemsOfBatch($batchId);
        if ($items === []) {
            throw new ArchiveException('Lote não encontrado no arquivo.');
        }
        $root = $items[0];

        foreach ($items as $item) {
            if ($item['model'] === 'schema' && isset($item['meta']['quarantine'])) {
                SchemaQuarantine::purge((string) $item['meta']['quarantine']);
            }
        }
        if ($root['model'] === 'user') {
            SchemaProvisioner::dropMysqlAccount((string) $root['values']['mysql_login']);
        }

        DeletedModel::deleteBatch($batchId);
        AuditLog::record('archive.purged', $root['model'], $root['model_id'], ['batch' => $batchId, 'itens' => count($items), 'rotulo' => $root['label']]);
    }

    /** @return ?array<string, mixed> */
    private static function fetchRow(PDO $pdo, string $model, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM ' . ArchiveGraph::table($model) . ' WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Reinsere a linha arquivada. Colunas que deixaram de existir desde a exclusão (ex.:
     * users.deleted_at, removida na migração da lixeira antiga) são ignoradas.
     *
     * @param array<string, mixed> $values
     */
    private static function insertRow(PDO $pdo, string $table, array $values): void
    {
        $existing = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $existing->execute([$table]);
        $values = array_intersect_key($values, array_flip($existing->fetchAll(PDO::FETCH_COLUMN)));
        $columns = array_keys($values);
        foreach ($columns as $column) {
            if (preg_match('/^[a-z_]+$/', (string) $column) !== 1) {
                throw new ArchiveException("Coluna inesperada no arquivo: {$column}");
            }
        }
        $pdo->prepare(
            "INSERT INTO {$table} (" . implode(', ', array_map(static fn ($c) => "`{$c}`", $columns)) . ') VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')',
        )->execute(array_values($values));
    }

    /** @param list<array{model: string, row: array<string, mixed>, is_root: bool}> $items */
    private static function ownerLogin(PDO $pdo, int $userId, array $items): string
    {
        foreach ($items as $item) {
            if ($item['model'] === 'user' && (int) $item['row']['id'] === $userId) {
                return (string) $item['row']['mysql_login'];
            }
        }

        return self::currentLogin($pdo, $userId);
    }

    private static function currentLogin(PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare('SELECT mysql_login FROM users WHERE id = ?');
        $stmt->execute([$userId]);

        return (string) ($stmt->fetchColumn() ?: throw new ArchiveException("Dono do schema (usuário #{$userId}) não encontrado."));
    }

    /** @param list<string> $sqls */
    private static function saveObjectsScript(int $userId, string $dbName, array $sqls): void
    {
        try {
            SavedQuery::create($userId, mb_substr("Restaurar objetos de {$dbName}", 0, 80), $dbName, ProgrammableObjects::restoreScript($sqls));
        } catch (Throwable $e) {
            ErrorLogger::exception($e, 'warning');
        }
    }

    /** @param list<callable(): mixed> $undo */
    private static function runUndo(array $undo): void
    {
        foreach (array_reverse($undo) as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                // Desfazer não pode esconder o erro original; fica no log pro admin.
                ErrorLogger::exception($e, 'critical');
            }
        }
    }

    /** @return ?array{id: ?int, name: string} */
    private static function currentActor(): ?array
    {
        $user = Auth::user();

        return $user !== null ? ['id' => $user->id, 'name' => $user->name] : null;
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `composer test:integration -- --filter=Archiver`
Expected: PASS (5 tests).

Run: `vendor/bin/pest && vendor/bin/php-cs-fixer fix --dry-run`
Expected: suíte verde (integração aparece como skipped), `Found 0 of ... files that can be fixed`.

- [ ] **Step 6: Commit**

```bash
git add app/Models/DeletedModel.php app/Services/Archiver.php tests/Integration/ArchiverTest.php
git commit -m "Archiver: arquivar, restaurar e excluir definitivamente, em lote e auditado"
```

---

### Task 7: Reservas de nome enquanto o lote existe

**Files:**
- Modify: `app/Models/DeletedModel.php`, `app/Models/User.php` (`emailExists`, `isLoginTaken`), `app/Models/SchemaRecord.php` (`nameTaken`)
- Test: `tests/Integration/ReservedNamesTest.php`

**Interfaces:**
- Produces: `DeletedModel::isReserved(string $field, string $value): bool` com `$field` em `'email' | 'mysql_login' | 'schema_prefix' | 'db_name'`.

- [ ] **Step 1: Teste que falha**

`tests/Integration/ReservedNamesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\SchemaRecord;
use App\Models\User;
use App\Services\Archiver;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('keeps e-mail, login, prefix and schema names reserved while the batch is archived', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    $batch = Archiver::archive('user', $user->id, 'user.deleted');

    expect(User::emailExists($user->email))->toBeTrue()
        ->and(User::isLoginTaken($user->mysqlLogin))->toBeTrue()
        ->and(SchemaRecord::nameTaken($db))->toBeTrue();

    Archiver::purge($batch);

    expect(User::emailExists($user->email))->toBeFalse()
        ->and(User::isLoginTaken($user->mysqlLogin))->toBeFalse()
        ->and(SchemaRecord::nameTaken($db))->toBeFalse();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `composer test:integration -- --filter=ReservedNames`
Expected: FAIL — `emailExists` devolve `false` logo depois de arquivar.

- [ ] **Step 3: Implementar**

Em `app/Models/DeletedModel.php`, adicionar:

```php
    private const RESERVABLE = [
        'email' => ['user', '$.email'],
        'mysql_login' => ['user', '$.mysql_login'],
        'schema_prefix' => ['user', '$.schema_prefix'],
        'db_name' => ['schema', '$.db_name'],
    ];

    /**
     * E-mail/login/prefixo de conta e nome de schema que estão no arquivo continuam "ocupados"
     * até a exclusão definitiva: a conta MySQL bloqueada ainda existe, e liberar o nome faria a
     * restauração (ou o CREATE USER de outra pessoa) bater nele.
     */
    public static function isReserved(string $field, string $value): bool
    {
        [$model, $path] = self::RESERVABLE[$field] ?? throw new \InvalidArgumentException("Campo não reservável: {$field}");
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM deleted_models WHERE model = ? AND JSON_UNQUOTE(JSON_EXTRACT(`values`, ?)) = ? LIMIT 1',
        );
        $stmt->execute([$model, $path, $value]);

        return $stmt->fetch() !== false;
    }
```

Em `app/Models/User.php`, trocar `emailExists()` por:

```php
    public static function emailExists(string $email): bool
    {
        return self::findByEmail($email) !== null || DeletedModel::isReserved('email', $email);
    }
```

E em `isLoginTaken()`, trocar o `return $stmt->fetch() !== false;` final por:

```php
        return $stmt->fetch() !== false
            || DeletedModel::isReserved('mysql_login', $login)
            || DeletedModel::isReserved('schema_prefix', $login);
```

(`DeletedModel` está no mesmo namespace `App\Models`; não precisa de `use`.)

Em `app/Models/SchemaRecord.php`, trocar o `return` de `nameTaken()` por:

```php
        return $stmt->fetch() !== false || DeletedModel::isReserved('db_name', $dbName);
```

E em `reconcileForUser()`, o `if (!self::nameTaken($newName))` continua como está (um nome reservado não é registrado pela sincronização; a restauração acusa o conflito do database recriado).

- [ ] **Step 4: Rodar e ver passar**

Run: `composer test:integration && vendor/bin/pest`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add app/Models/DeletedModel.php app/Models/User.php app/Models/SchemaRecord.php tests/Integration/ReservedNamesTest.php
git commit -m "Nomes de contas e schemas arquivados ficam reservados até a exclusão definitiva"
```

---

### Task 8: Pontos de exclusão passam pelo Archiver

**Files:**
- Modify: `app/Actions/Schema/DestroySchemaAction.php`, `app/Actions/Admin/DestroyAdminSchemaAction.php`, `app/Actions/SavedQuery/DestroySavedQueryAction.php`, `app/Actions/ErDiagram/DestroyErDiagramAction.php`, `app/Actions/Student/DestroyStudentAction.php`, `app/Actions/Admin/DestroyAdminUserAction.php`, `app/Models/SchemaRecord.php` (`reconcileForUser`), `app/Views/admin/users.twig`
- Test: `tests/Integration/ArchiverTest.php` (caso da sincronização)

**Interfaces:**
- Consumes: `Archiver::archive()` (Task 6), `ArchiveException`.

- [ ] **Step 1: Teste que falha (sincronização do console)**

Adicionar a `tests/Integration/ArchiverTest.php`:

```php
it('archives the record when the console sync finds a schema dropped outside the platform', function () {
    $user = integrationUser();
    $db = integrationSchema($user);
    Database::connection()->exec("DROP DATABASE `{$db}`");

    SchemaRecord::reconcileForUser($user->id, $user->schemaPrefix, []);

    $stmt = Database::connection()->prepare("SELECT JSON_EXTRACT(meta, '$.removed_outside') FROM deleted_models WHERE model = 'schema' AND label = ?");
    $stmt->execute([$db]);
    expect(SchemaRecord::nameTaken($db))->toBeTrue() // reservado no arquivo
        ->and($stmt->fetchColumn())->toBe('true');
});
```

Run: `composer test:integration -- --filter="console sync"`
Expected: FAIL — nada em `deleted_models` (o registro foi apagado com `DELETE`).

- [ ] **Step 2: `reconcileForUser`**

Em `app/Models/SchemaRecord.php`, adicionar `use App\Services\Archiver;` e, no laço final de `reconcileForUser()`, trocar `self::delete($schema->id);` por:

```php
                // DROP DATABASE feito por fora (console/phpMyAdmin/SGBD): não tem como evitar nem
                // recuperar os dados, mas o registro vai pro arquivo e fica auditado.
                Archiver::archive('schema', $schema->id, 'schema.removed_outside', origin: Archiver::ORIGIN_OUTSIDE);
```

Remover o método `delete()` de `SchemaRecord` (sem outros usos após este task: `grep -rn "SchemaRecord::delete" app` deve voltar vazio).

- [ ] **Step 3: Schemas (dono e admin)**

`app/Actions/Schema/DestroySchemaAction.php` — trocar os `use` de `AuditLog` e `SchemaProvisioner` por `use App\Services\ArchiveException;` e `use App\Services\Archiver;`, e o bloco `try` por:

```php
        try {
            Archiver::archive('schema', $schema->id, 'schema.deleted');
            $this->respond(true, "Schema \"{$dbName}\" removido. Se precisar dele de volta, um admin consegue restaurar.", '/dashboard');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/dashboard');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o schema', $e), '/dashboard');
        }
```

`app/Actions/Admin/DestroyAdminSchemaAction.php` — mesmo troca de `use`, e:

```php
        try {
            Archiver::archive('schema', $record->id, 'schema.deleted', ['por' => 'admin']);
            $this->respond(true, "Schema \"{$dbName}\" movido para Dados excluídos.", '/admin/schemas');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/schemas');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o schema', $e), '/admin/schemas');
        }
```

- [ ] **Step 4: Consulta salva e diagrama**

`app/Actions/SavedQuery/DestroySavedQueryAction.php` — adicionar `use App\Services\Archiver;` e trocar `SavedQuery::delete($query->id);` por `Archiver::archive('saved_query', $query->id, 'saved_query.deleted');`.

`app/Actions/ErDiagram/DestroyErDiagramAction.php` — adicionar `use App\Services\Archiver;` e trocar `ErDiagram::delete($diagram->id);` por `Archiver::archive('er_diagram', $diagram->id, 'er_diagram.deleted');`.

Remover `SavedQuery::delete()` e `ErDiagram::delete()` dos models (`grep -rn "SavedQuery::delete\|ErDiagram::delete" app` vazio).

- [ ] **Step 5: Usuários (professor e admin)**

`app/Actions/Student/DestroyStudentAction.php` — trocar `use App\Models\AuditLog;` e `use App\Services\UserManager;` por `use App\Services\ArchiveException;` e `use App\Services\Archiver;`, e o `try` por:

```php
        try {
            Archiver::archive('user', $student->id, 'student.deleted', ['email' => $student->email]);
            $this->respond(true, "Conta de {$student->name} excluída (um admin pode restaurar em Dados excluídos).", '/professor/alunos');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/professor/alunos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a conta', $e), '/professor/alunos');
        }
```

`app/Actions/Admin/DestroyAdminUserAction.php` — mesma troca de `use`, e:

```php
        try {
            Archiver::archive('user', $target->id, 'user.deleted', ['email' => $target->email]);
            $this->respond(true, "Conta de {$target->name} movida para Dados excluídos.", '/admin/usuarios');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/usuarios');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a conta', $e), '/admin/usuarios');
        }
```

Em `app/Views/admin/users.twig`, no `confirmMessage` do botão de excluir (linha com `'Excluir a conta de {{ user.name|e('js') }}?`), trocar o texto que fala em "lixeira" por: `Excluir a conta de {{ user.name|e('js') }}? Ela vai para Dados excluídos com os schemas, consultas e diagramas, e pode ser restaurada.`

- [ ] **Step 6: Rodar tudo**

Run: `composer test:integration && vendor/bin/pest && vendor/bin/php-cs-fixer fix --dry-run`
Expected: tudo verde; 0 arquivos a corrigir.

Manual (stack local): logado como `aluno@dblab.local`, criar e excluir um schema no `/dashboard`; logado como admin, conferir em `/admin/auditoria?q=schema.deleted` a entrada com `batch`, e no MySQL: `docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW DATABASES LIKE \"\\_lixeira%\""'` mostra a quarentena.

- [ ] **Step 7: Commit**

```bash
git add app/Actions app/Models/SchemaRecord.php app/Models/SavedQuery.php app/Models/ErDiagram.php app/Views/admin/users.twig tests/Integration/ArchiverTest.php
git commit -m "Exclusões de schema, consulta, diagrama e conta passam pelo arquivo"
```

---

### Task 9: Migrar a lixeira antiga e remover `users.deleted_at`

**Files:**
- Create: `database/migrations/2026_10_08_000002_move_trashed_users_to_deleted_models.php`
- Modify: `app/Models/User.php`, `app/Models/Entities/User.php`, `app/Services/UserManager.php`, `app/Models/AdminMetrics.php`, `app/Support/AdminStats.php`, `app/Views/admin/index.twig`, `public/index.php`
- Delete: `app/Actions/Admin/TrashAdminUsersAction.php`, `app/Actions/Admin/RestoreAdminUserAction.php`, `app/Views/admin/trash.twig`
- Create: `app/Actions/Admin/RedirectTrashAction.php`

**Interfaces:**
- Consumes: `Archiver::archive()`, `Archiver::restore()`, `DeletedModel::countBatches()`.
- Produces: `AdminStats::$deletedBatches` (substitui `trashedUsers`); rota `GET /admin/usuarios/lixeira` → 302 para `/admin/excluidos?tipo=user`.

- [ ] **Step 1: Migration de dados**

`database/migrations/2026_10_08_000002_move_trashed_users_to_deleted_models.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Migration;
use App\Models\DeletedModel;
use App\Services\Archiver;
use App\Services\SchemaProvisioner;

/**
 * Leva quem estava na lixeira antiga (users.deleted_at) pro arquivo de excluídos, do mesmo
 * jeito que uma exclusão nova faria (lote com schemas em quarentena), e remove a coluna.
 */
return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $ids = $pdo->query('SELECT id FROM users WHERE deleted_at IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            Archiver::archive('user', (int) $id, 'user.deleted', ['origem' => 'migração da lixeira antiga'], actor: ['id' => null, 'name' => 'migração']);
        }

        $pdo->exec('ALTER TABLE users DROP COLUMN deleted_at');
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');

        $batches = $pdo->query("SELECT batch_id, model_id FROM deleted_models WHERE is_root = 1 AND model = 'user'")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($batches as $batchId => $userId) {
            $login = (string) DeletedModel::itemsOfBatch($batchId)[0]['values']['mysql_login'];
            Archiver::restore($batchId);
            $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = ?')->execute([$userId]);
            SchemaProvisioner::lockMysqlAccount($login);
        }
    }
};
```

- [ ] **Step 2: Tirar `deleted_at` do código**

`app/Models/User.php`:
- Docblock da classe: trocar o parágrafo de soft delete por `Exclusão passa sempre por App\Services\Archiver: a linha sai daqui e vai para deleted_models.`
- Remover ` AND deleted_at IS NULL` de `find`, `findByEmail`, `findByEmailOrUsername`, `all` (os dois SELECTs), `countByRole`; em `allManageable` o WHERE fica `WHERE role <> ?`.
- Remover os métodos `trashed()`, `findTrashed()`, `softDelete()`, `restore()`.

`app/Models/Entities/User.php`: remover o parâmetro `public ?DateTimeImmutable $deletedAt,`, o método `isDeleted()` e a linha `deletedAt: ...` de `fromRow()`.

`app/Services/UserManager.php`: remover os métodos `softDelete()` e `restore()` (e seus docblocks).

`app/Models/AdminMetrics.php`: remover ` AND deleted_at IS NULL` / `deleted_at IS NULL AND ` das consultas; trocar a linha `trashedUsers: ...` por `deletedBatches: DeletedModel::countBatches(),`.

`app/Support/AdminStats.php`: renomear `public int $trashedUsers = 0,` para `public int $deletedBatches = 0,`.

`app/Views/admin/index.twig`: trocar `{{ m.stat('Na lixeira', stats.trashedUsers) }}` por `{{ m.stat('Dados excluídos', stats.deletedBatches, 'lotes aguardando revisão') }}`.

Run: `grep -rn "deleted_at\|deletedAt\|trashed\|findTrashed\|softDelete" app public | grep -v deleted_models`
Expected: nenhuma linha.

- [ ] **Step 3: Rotas e redirect da lixeira antiga**

`app/Actions/Admin/RedirectTrashAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;

/** A lixeira de usuários virou um filtro de Dados excluídos. GET /admin/usuarios/lixeira. */
final class RedirectTrashAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $this->redirect('/admin/excluidos?tipo=user');
    }
}
```

Em `public/index.php`: remover os `use` de `TrashAdminUsersAction` e `RestoreAdminUserAction`, adicionar `use App\Actions\Admin\RedirectTrashAction;`, trocar `$router->get('/admin/usuarios/lixeira', TrashAdminUsersAction::class);` por `$router->get('/admin/usuarios/lixeira', RedirectTrashAction::class);` e remover a linha `$router->post('/admin/usuarios/{id}/restaurar', RestoreAdminUserAction::class);`.

Apagar `app/Actions/Admin/TrashAdminUsersAction.php`, `app/Actions/Admin/RestoreAdminUserAction.php`, `app/Views/admin/trash.twig`.

- [ ] **Step 4: Rodar a migration e conferir**

Preparar um usuário na lixeira antiga ANTES de rodar (para provar a migração): no `/admin/usuarios`, criar um aluno de teste com um schema e, no MySQL, `UPDATE users SET deleted_at = NOW() WHERE email = '<e-mail dele>'`.

Run: `docker compose up -d --build app && docker compose logs app | grep -i "migrat"`
Expected: `Migrated: 2026_10_08_000002_move_trashed_users_to_deleted_models`.

Run: `docker compose exec mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -e "SELECT model, label FROM schoolapp.deleted_models; SHOW COLUMNS FROM schoolapp.users LIKE \"deleted_at\""' 2>/dev/null`
Expected: as linhas do aluno de teste (`user` + `schema`) e nenhuma coluna `deleted_at`.

Run: `vendor/bin/pest && composer test:integration && vendor/bin/php-cs-fixer fix --dry-run`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add -A database/migrations/2026_10_08_000002_move_trashed_users_to_deleted_models.php app public/index.php
git commit -m "Lixeira de usuários migra para o arquivo de excluídos; users.deleted_at removida"
```

---

### Task 10: Tela `/admin/excluidos`

**Files:**
- Modify: `app/Models/DeletedModel.php` (listagem)
- Create: `app/Actions/Admin/IndexDeletedModelsAction.php`, `app/Actions/Admin/RestoreDeletedBatchAction.php`, `app/Actions/Admin/PurgeDeletedBatchAction.php`, `app/Views/admin/deleted.twig`
- Modify: `public/index.php`, `app/Views/partials/nav-links.twig`, `app/Views/admin/index.twig`, `app/Views/admin/users.twig`
- Test: `tests/Integration/DeletedModelListingTest.php`

**Interfaces:**
- Consumes: `Archiver::restore/purge/conflicts`, `ArchiveGraph::typeLabel`, `ArchiveSnapshot::forDisplay`, `SchemaQuarantine::sizeBytes`, `App\Support\Paginator`.
- Produces:
  - `DeletedModel::paginateBatches(?string $model, string $search, int $days, int $page, int $perPage = 15): Paginator` — cada item: `array{batch_id: string, model: string, label: string, deleted_by_name: ?string, deleted_at: string, removed_outside: bool, related: array<string, int>, quarantine_bytes: int, items: list<array{model: string, model_id: int, label: string, values: array}>}`.
  - Rotas: `GET /admin/excluidos`, `POST /admin/excluidos/{batch}/restaurar`, `POST /admin/excluidos/{batch}/excluir`.

- [ ] **Step 1: Teste que falha (listagem)**

`tests/Integration/DeletedModelListingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\DeletedModel;
use App\Services\Archiver;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('lists one row per batch with related counts, filtered by type and search', function () {
    $user = integrationUser();
    integrationSchema($user);
    App\Models\SavedQuery::create($user->id, 'q-it listada', null, 'SELECT 1');
    $batch = Archiver::archive('user', $user->id, 'user.deleted');

    $page = DeletedModel::paginateBatches('user', $user->email, 0, 1);
    expect($page->total)->toBe(1)
        ->and($page->items[0]['batch_id'])->toBe($batch)
        ->and($page->items[0]['related'])->toBe(['schema' => 1, 'saved_query' => 1])
        ->and($page->items[0]['items'])->toHaveCount(3)
        ->and($page->items[0]['removed_outside'])->toBeFalse();

    expect(DeletedModel::paginateBatches('schema', $user->email, 0, 1)->total)->toBe(0)
        ->and(DeletedModel::paginateBatches(null, 'q-it listada', 0, 1)->total)->toBe(1);
});
```

Run: `composer test:integration -- --filter=DeletedModelListing`
Expected: FAIL — `Call to undefined method App\Models\DeletedModel::paginateBatches()`.

- [ ] **Step 2: Implementar a listagem**

Em `app/Models/DeletedModel.php`, adicionar `use App\Services\SchemaQuarantine;` e `use App\Support\Paginator;`, e:

```php
    public static function paginateBatches(?string $model, string $search, int $days, int $page, int $perPage = 15): Paginator
    {
        $where = ['d.is_root = 1'];
        $args = [];
        if ($model !== null && $model !== '') {
            $where[] = 'd.model = ?';
            $args[] = $model;
        }
        if ($days > 0) {
            $where[] = 'd.deleted_at >= NOW() - INTERVAL ? DAY';
            $args[] = $days;
        }
        if ($search !== '') {
            // Busca no rótulo de qualquer item do lote (ex.: achar um usuário pelo nome de um schema dele).
            $where[] = '(d.label LIKE ? OR EXISTS (SELECT 1 FROM deleted_models x WHERE x.batch_id = d.batch_id AND x.label LIKE ?))';
            array_push($args, "%{$search}%", "%{$search}%");
        }
        $whereSql = implode(' AND ', $where);
        $pdo = Database::connection();

        $count = $pdo->prepare("SELECT COUNT(*) FROM deleted_models d WHERE {$whereSql}");
        $count->execute($args);
        $total = (int) $count->fetchColumn();
        $page = max(1, min($page, (int) ceil(max($total, 1) / $perPage)));

        $stmt = $pdo->prepare(
            "SELECT d.batch_id FROM deleted_models d WHERE {$whereSql} ORDER BY d.deleted_at DESC, d.id DESC LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage),
        );
        $stmt->execute($args);
        $batchIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        return new Paginator(self::describeBatches($batchIds), $page, $perPage, $total);
    }

    /**
     * @param list<string> $batchIds
     * @return list<array<string, mixed>>
     */
    private static function describeBatches(array $batchIds): array
    {
        if ($batchIds === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM deleted_models WHERE batch_id IN (' . implode(',', array_fill(0, count($batchIds), '?')) . ') ORDER BY is_root DESC, id',
        );
        $stmt->execute($batchIds);

        $byBatch = array_fill_keys($batchIds, []);
        foreach ($stmt->fetchAll() as $row) {
            $byBatch[$row['batch_id']][] = self::hydrate($row);
        }

        $quarantines = [];
        foreach ($byBatch as $items) {
            foreach ($items as $item) {
                if (isset($item['meta']['quarantine'])) {
                    $quarantines[] = (string) $item['meta']['quarantine'];
                }
            }
        }
        $sizes = SchemaQuarantine::sizeBytes($quarantines);

        $batches = [];
        foreach ($byBatch as $batchId => $items) {
            $root = $items[0];
            $related = [];
            $bytes = 0;
            foreach ($items as $item) {
                if (!$item['is_root']) {
                    $related[$item['model']] = ($related[$item['model']] ?? 0) + 1;
                }
                $bytes += $sizes[$item['meta']['quarantine'] ?? ''] ?? 0;
            }
            $batches[] = [
                'batch_id' => $batchId,
                'model' => $root['model'],
                'label' => $root['label'],
                'deleted_by_name' => $root['deleted_by_name'],
                'deleted_at' => $root['deleted_at'],
                'removed_outside' => ($root['meta']['removed_outside'] ?? false) === true,
                'related' => $related,
                'quarantine_bytes' => $bytes,
                'items' => array_map(static fn (array $i): array => [
                    'model' => $i['model'],
                    'model_id' => $i['model_id'],
                    'label' => $i['label'],
                    'values' => $i['values'],
                ], $items),
            ];
        }

        return $batches;
    }
```

Run: `composer test:integration -- --filter=DeletedModelListing`
Expected: PASS.

- [ ] **Step 3: Actions**

`app/Actions/Admin/IndexDeletedModelsAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\DeletedModel;
use App\Support\ArchiveGraph;
use App\Support\ArchiveSnapshot;
use App\Support\Paginator;

/** Dados excluídos: lotes do arquivo para restaurar ou excluir definitivamente. GET /admin/excluidos. */
final class IndexDeletedModelsAction extends Action
{
    private const PERIODS = [0, 7, 30, 90, 365];

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $model = in_array($_GET['tipo'] ?? '', ArchiveGraph::MODELS, true) ? (string) $_GET['tipo'] : '';
        $search = trim((string) ($_GET['q'] ?? ''));
        $days = in_array((int) ($_GET['dias'] ?? 0), self::PERIODS, true) ? (int) ($_GET['dias'] ?? 0) : 0;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $found = DeletedModel::paginateBatches($model, $search, $days, $page);
        // Segredos e textos longos nunca chegam no template (Paginator é readonly: monta outro).
        $paginator = new Paginator(array_map(static function (array $batch): array {
            $batch['items'] = array_map(static fn (array $i): array => ['values' => ArchiveSnapshot::forDisplay($i['values'])] + $i, $batch['items']);

            return $batch;
        }, $found->items), $found->page, $found->perPage, $found->total);

        $this->render('admin/deleted', [
            'pageTitle' => 'Dados excluídos',
            'paginator' => $paginator,
            'models' => array_combine(ArchiveGraph::MODELS, array_map(ArchiveGraph::typeLabel(...), ArchiveGraph::MODELS)),
            'periods' => self::PERIODS,
            'filters' => ['tipo' => $model, 'q' => $search, 'dias' => $days],
        ]);
    }
}
```

`app/Actions/Admin/RestoreDeletedBatchAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\Archiver;
use Throwable;

/** POST /admin/excluidos/{batch}/restaurar. */
final class RestoreDeletedBatchAction extends Action
{
    public const BATCH_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $batch = (string) ($params['batch'] ?? '');
        if (preg_match(self::BATCH_PATTERN, $batch) !== 1) {
            $this->respond(false, 'Lote inválido.', '/admin/excluidos');
        }

        try {
            Archiver::restore($batch);
            $this->respond(true, 'Restaurado. Se havia views, triggers ou rotinas, elas estão na biblioteca de consultas do dono como "Restaurar objetos de …".', '/admin/excluidos');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/excluidos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('restaurar', $e), '/admin/excluidos');
        }
    }
}
```

`app/Actions/Admin/PurgeDeletedBatchAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Models\DeletedModel;
use App\Services\ArchiveException;
use App\Services\Archiver;
use Throwable;

/** Exclusão definitiva — pede o rótulo digitado como confirmação. POST /admin/excluidos/{batch}/excluir. */
final class PurgeDeletedBatchAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $batch = (string) ($params['batch'] ?? '');
        $items = preg_match(RestoreDeletedBatchAction::BATCH_PATTERN, $batch) === 1 ? DeletedModel::itemsOfBatch($batch) : [];
        if ($items === []) {
            $this->respond(false, 'Lote não encontrado.', '/admin/excluidos');
        }
        if (trim((string) ($_POST['confirmacao'] ?? '')) !== $items[0]['label']) {
            $this->respond(false, 'Para excluir definitivamente, digite exatamente: ' . $items[0]['label'], '/admin/excluidos');
        }

        try {
            Archiver::purge($batch);
            $this->respond(true, "\"{$items[0]['label']}\" excluído definitivamente.", '/admin/excluidos');
        } catch (ArchiveException $e) {
            $this->respond(false, $e->getMessage(), '/admin/excluidos');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir definitivamente', $e), '/admin/excluidos');
        }
    }
}
```

- [ ] **Step 4: View**

`app/Views/admin/deleted.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% block content %}
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-slate-900">Dados excluídos</h1>
        <p class="mt-1 text-sm text-slate-500">
            Nada é apagado direto: o que é excluído na plataforma fica aqui, em lotes (uma conta vem com seus schemas,
            consultas e diagramas). Restaure ou exclua definitivamente — as duas ações ficam na
            <a href="/admin/auditoria?q=archive." class="text-indigo-600 hover:text-indigo-700">auditoria</a>.
        </p>
    </div>

    <form method="get" action="/admin/excluidos" class="card mb-6 grid gap-3 sm:grid-cols-[1fr_auto_auto_auto]">
        <input type="search" name="q" value="{{ filters.q }}" placeholder="Buscar por nome, e-mail, schema…" class="field-input">
        <select name="tipo" class="field-input !w-auto">
            <option value="">Todos os tipos</option>
            {% for key, label in models %}
                <option value="{{ key }}" {{ filters.tipo == key ? 'selected' }}>{{ label }}</option>
            {% endfor %}
        </select>
        <select name="dias" class="field-input !w-auto">
            {% for d in periods %}
                <option value="{{ d }}" {{ filters.dias == d ? 'selected' }}>{{ d == 0 ? 'Qualquer data' : 'Últimos ' ~ d ~ ' dias' }}</option>
            {% endfor %}
        </select>
        <button type="submit" class="btn-secondary">Filtrar</button>
    </form>

    {% if paginator.total == 0 %}
        <div class="card py-10 text-center text-sm text-slate-500">Nada por aqui.</div>
    {% else %}
        <ul class="space-y-3">
            {% for b in paginator.items %}
                <li class="card" x-data="{ open: false }" data-row>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <button type="button" @click="open = !open" class="min-w-0 flex-1 text-left">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ models[b.model] }}</p>
                            <p class="mt-0.5 truncate font-medium text-slate-900">{{ b.label }}</p>
                            <p class="mt-1 text-xs text-slate-500">
                                {% for model, n in b.related %}+{{ n }} {{ models[model]|lower }}{{ not loop.last ? ', ' }}{% endfor %}
                                {% if b.related is not empty %} · {% endif %}
                                excluído {% if b.deleted_by_name %}por {{ b.deleted_by_name }} {% endif %}em {{ b.deleted_at|date('d/m/Y H:i') }}
                                {% if b.quarantine_bytes > 0 %} · {{ b.quarantine_bytes|bytes }} em quarentena{% endif %}
                            </p>
                            {% if b.removed_outside %}
                                <span class="mt-2 inline-block rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700">removido fora da plataforma — sem dados para restaurar</span>
                            {% endif %}
                        </button>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            {% if not b.removed_outside %}
                                <form
                                    hx-boost="false" method="post" action="/admin/excluidos/{{ b.batch_id }}/restaurar"
                                    x-data="ajaxForm({
                                        confirmTitle: 'Restaurar',
                                        confirmMessage: 'Restaurar \'{{ b.label|e('js') }}\'{{ b.related is not empty ? ' com os itens relacionados' : '' }}?',
                                        confirmLabel: 'Restaurar',
                                        danger: false,
                                        refresh: true,
                                    })" @submit.prevent="submit"
                                >
                                    {{ csrf_field() }}
                                    <button :disabled="loading" type="submit" class="btn-secondary !px-3 !py-1.5 text-xs">Restaurar</button>
                                </form>
                            {% endif %}
                            <form
                                hx-boost="false" method="post" action="/admin/excluidos/{{ b.batch_id }}/excluir"
                                x-data="ajaxForm({
                                    confirmTitle: 'Excluir definitivamente',
                                    confirmMessage: 'Isso apaga \'{{ b.label|e('js') }}\'{{ b.related is not empty ? ' e os itens relacionados' : '' }} para sempre (inclusive os dados em quarentena). Não tem volta.',
                                    confirmLabel: 'Excluir para sempre',
                                    refresh: true,
                                })" @submit.prevent="submit"
                                class="flex items-center gap-2"
                            >
                                {{ csrf_field() }}
                                <input type="text" name="confirmacao" required placeholder="Digite: {{ b.label }}" class="field-input !w-56 !py-1.5 text-xs" autocomplete="off">
                                <button :disabled="loading" type="submit" class="btn-danger !px-3 !py-1.5 text-xs">Excluir</button>
                            </form>
                        </div>
                    </div>

                    <div x-show="open" x-cloak class="mt-4 space-y-3 border-t border-slate-100 pt-4">
                        {% for item in b.items %}
                            <details class="rounded-lg bg-slate-50 px-3 py-2 text-xs" {{ loop.first ? 'open' }}>
                                <summary class="cursor-pointer font-medium text-slate-700">{{ models[item.model] }} #{{ item.model_id }} · {{ item.label }}</summary>
                                <dl class="mt-2 grid gap-x-4 gap-y-1 sm:grid-cols-[12rem_1fr]">
                                    {% for key, value in item.values %}
                                        <dt class="font-mono text-slate-500">{{ key }}</dt>
                                        <dd class="break-words font-mono text-slate-800">{{ value is null ? 'NULL' : value }}</dd>
                                    {% endfor %}
                                </dl>
                            </details>
                        {% endfor %}
                    </div>
                </li>
            {% endfor %}
        </ul>
        {# Paginação própria: o macro tc.pagination espera um TableQuery, e aqui os filtros são outros. #}
        <div class="mt-4 flex items-center justify-between text-sm text-slate-500">
            <span>Mostrando {{ paginator.from() }}–{{ paginator.to() }} de {{ paginator.total }} lote{{ paginator.total == 1 ? '' : 's' }}</span>
            <div class="flex items-center gap-2">
                {% if paginator.hasPrevious() %}
                    <a href="/admin/excluidos?{{ filters|merge({ page: paginator.page - 1 })|url_encode }}" class="btn-secondary !px-3 !py-1.5 text-xs">← Anterior</a>
                {% endif %}
                {% if paginator.hasNext() %}
                    <a href="/admin/excluidos?{{ filters|merge({ page: paginator.page + 1 })|url_encode }}" class="btn-secondary !px-3 !py-1.5 text-xs">Próxima →</a>
                {% endif %}
            </div>
        </div>
    {% endif %}
{% endblock %}
```

- [ ] **Step 5: Rotas, menu e atalhos**

`public/index.php`: adicionar os `use` de `IndexDeletedModelsAction`, `RestoreDeletedBatchAction`, `PurgeDeletedBatchAction` e, junto das rotas `/admin/*`:

```php
$router->get('/admin/excluidos', IndexDeletedModelsAction::class);
$router->post('/admin/excluidos/{batch}/restaurar', RestoreDeletedBatchAction::class);
$router->post('/admin/excluidos/{batch}/excluir', PurgeDeletedBatchAction::class);
```

`app/Views/partials/nav-links.twig`: nos dois menus admin (mobile e dropdown), depois de "Backups", adicionar `<a href="/admin/excluidos" ...>Dados excluídos</a>` com a mesma classe dos vizinhos.

`app/Views/admin/index.twig`: na lista de cards de links, adicionar `{ href: '/admin/excluidos', title: 'Dados excluídos', text: 'Restaure ou exclua definitivamente contas, schemas, consultas e diagramas excluídos.' },`.

`app/Views/admin/users.twig`: trocar `<a href="/admin/usuarios/lixeira" class="btn-secondary">Lixeira</a>` por `<a href="/admin/excluidos?tipo=user" class="btn-secondary">Dados excluídos</a>`.

- [ ] **Step 6: Verificar no navegador**

`docker compose up -d --build app`, logado como admin (`admin@dblab.local` / `password123`):
1. Em `/admin/usuarios`, criar um aluno, entrar como ele numa janela anônima, criar um schema com uma tabela e um trigger no console; voltar e excluir a conta pelo `/admin/usuarios`.
2. `/admin/excluidos` lista o lote: "Usuário · <nome>", "+1 schema", espaço em quarentena; expandir mostra `password_hash = [omitido]`.
3. "Restaurar" → toast, o lote some, a conta loga de novo, o schema tem a tabela com os dados e a biblioteca de consultas tem "Restaurar objetos de …"; rodar essa consulta recria o trigger.
4. Excluir a conta de novo; em "Excluir", digitar o rótulo errado → erro; certo → toast, lote some; `/admin/auditoria?q=archive.` mostra `archive.restored` e `archive.purged`.
5. Conferir que a página não recarrega em nenhum passo (só toasts e troca de conteúdo).

Run: `vendor/bin/pest && composer test:integration && vendor/bin/php-cs-fixer fix --dry-run && npx prettier --check "resources/**/*.{js,css}"`
Expected: tudo verde.

- [ ] **Step 7: Commit**

```bash
git add app/Models/DeletedModel.php app/Actions/Admin app/Views public/index.php tests/Integration/DeletedModelListingTest.php
git commit -m "Tela Dados excluídos: restaurar ou excluir definitivamente, por lote"
```

---

### Task 11: Documentação

**Files:**
- Modify: `README.md`, `SECURITY.md`

- [ ] **Step 1: README**

Na tabela "Painel do admin e observabilidade", trocar a menção à lixeira de usuários e adicionar a linha:

```markdown
| `/admin/excluidos` | **Dados excluídos.** Nada de negócio é apagado direto: contas, schemas, consultas salvas e diagramas vão para `deleted_models` em lotes (uma conta leva seus schemas, consultas e diagramas). Schemas ficam em quarentena (`_lixeira_s<id>`, fora do alcance do aluno); views/triggers/rotinas voltam como uma consulta "Restaurar objetos de …" na biblioteca do dono. Restaurar e excluir definitivamente são por lote e auditados; e-mail, login e nome de schema ficam reservados até a exclusão definitiva. `DROP DATABASE` feito fora da plataforma não tem volta — o registro só fica arquivado e auditado. |
```

Na seção de testes, adicionar: `composer test:integration` — testes contra o MySQL do `docker compose` (arquivo de excluídos, quarentena).

- [ ] **Step 2: SECURITY.md**

Adicionar uma seção curta "Arquivo de dados excluídos" explicando: (1) por que objetos programáveis não são recriados pela app (evitar `SET_USER_ID` e escalada via `DEFINER`); (2) `deleted_models.values` guarda `password_hash` (necessário para restaurar) e a tela mascara; (3) quarentena fica fora do `GRANT` por prefixo do aluno.

- [ ] **Step 3: Commit**

```bash
git add README.md SECURITY.md
git commit -m "Documenta o arquivo de dados excluídos"
```

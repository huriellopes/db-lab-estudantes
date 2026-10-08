# Instituições — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O admin cria instituições e vincula professores (várias por professor) e alunos (no máximo uma por aluno); professor passa a ver só os alunos das instituições dele; aluno entra numa instituição pelo código de convite no cadastro.

**Architecture:** Tabelas `institutions` e `institution_members` (vínculo com papel; um UNIQUE numa coluna gerada garante "aluno em uma só" no MySQL). Regras puras em `App\Support` (`InviteCode`, `InstitutionScope`), consultas em `App\Models\Institution`/`InstitutionMember`, escrita em `App\Services\InstitutionManager`. Exclusões e remoções de vínculo passam pelo `Archiver` da etapa 1, que ganha os dois modelos novos.

**Tech Stack:** PHP 8.5 sem framework, PDO/MySQL 8.0, Twig 3, Alpine.js + Axios + htmx, Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-08-instituicoes-design.md`

## Global Constraints

- Só admin gerencia instituições e vínculos (`Auth::requireAdmin()`).
- Professor: várias instituições. Aluno: no máximo uma (garantido pelo MySQL: `uq_student_one_institution`). Admin nunca é membro.
- Professor só vê/gerencia alunos de instituições em comum; fora do escopo → "Aluno não encontrado.".
- Código de convite: formato `XXXX-XXXX`, alfabeto `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`; `NULL` = desativado. No cadastro é opcional; inválido recusa o cadastro.
- Exclusão de instituição e remoção de vínculo: sempre via `Archiver` (nada é apagado direto). Nome e código de instituição arquivada ficam reservados.
- Troca de papel: aluno→professor muda o vínculo; professor→aluno com mais de um vínculo é recusado; promover a admin arquiva os vínculos.
- Telas sem reload (hx-boost + `ajaxForm({ refresh: true })`). Textos e comentários em português. Lint (`php-cs-fixer`, Prettier) limpo.

## Mapa de arquivos

**Novos:** `database/migrations/2026_10_08_000003_create_institutions_tables.php`; `app/Support/InviteCode.php`, `app/Support/InstitutionScope.php`; `app/Models/Entities/Institution.php`, `app/Models/Institution.php`, `app/Models/InstitutionMember.php`; `app/Services/InstitutionException.php`, `app/Services/InstitutionManager.php`; Actions em `app/Actions/Institution/` (`IndexInstitutionsAction`, `StoreInstitutionAction`, `ShowInstitutionAction`, `UpdateInstitutionAction`, `InstitutionCodeAction`, `AddInstitutionMemberAction`, `RemoveInstitutionMemberAction`, `DestroyInstitutionAction`); views `app/Views/admin/institutions.twig`, `app/Views/admin/institution.twig`; testes `tests/Unit/InviteCodeTest.php`, `tests/Unit/InstitutionScopeTest.php`, `tests/Integration/InstitutionsTest.php`.

**Modificados:** `app/Support/ArchiveGraph.php`, `app/Support/ArchiveRestoreChecks.php`, `app/Services/Archiver.php`, `app/Models/DeletedModel.php`, `app/Actions/Student/StudentAction.php`, `app/Actions/Student/IndexStudentsAction.php`, `app/Models/Entities/StudentSummary.php`, `app/Views/professor/students/index.twig`, `app/Actions/Auth/RegisterAction.php`, `app/Views/auth/register.twig`, `app/Actions/Admin/UpdateAdminUserRoleAction.php`, `public/index.php`, `app/Views/partials/nav-links.twig`, `app/Views/admin/index.twig`, `tests/Pest.php`, `tests/Unit/ArchiveGraphTest.php`, `tests/Unit/ArchiveRestoreChecksTest.php`, `README.md`.

---

### Task 1: Tabelas `institutions` e `institution_members`

**Files:**
- Create: `database/migrations/2026_10_08_000003_create_institutions_tables.php`

**Interfaces:**
- Produces: tabelas da spec (com `student_user_id` gerada e `uq_student_one_institution`).

- [ ] **Step 1: Migration**

`database/migrations/2026_10_08_000003_create_institutions_tables.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS institutions (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(120) NOT NULL UNIQUE,
                -- XXXX-XXXX (App\Support\InviteCode). NULL = cadastro por código desativado.
                invite_code VARCHAR(9) NULL UNIQUE,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS institution_members (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                institution_id  INT NOT NULL,
                user_id         INT NOT NULL,
                role            ENUM('professor', 'aluno') NOT NULL,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                -- Só preenchida para aluno: o UNIQUE abaixo garante 'aluno em no máximo uma
                -- instituição' no próprio MySQL (NULLs não colidem), sem corrida entre requisições.
                -- VIRTUAL, não STORED: o MySQL recusa ON DELETE CASCADE na FK de user_id quando ele
                -- é base de uma coluna gerada STORED (erro 1215).
                student_user_id INT AS (IF(role = 'aluno', user_id, NULL)) VIRTUAL,
                UNIQUE KEY uq_member (institution_id, user_id),
                UNIQUE KEY uq_student_one_institution (student_user_id),
                INDEX idx_user (user_id),
                CONSTRAINT fk_member_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
                CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS institution_members');
        $pdo->exec('DROP TABLE IF EXISTS institutions');
    }
};
```

- [ ] **Step 2: Rodar (rebuild do app, a migration roda no boot) e testar down/up**

Run: `docker compose up -d --build app && docker compose logs app | grep Migrated | tail -1`
Expected: `Migrated: 2026_10_08_000003_create_institutions_tables`

Run: `docker compose exec app php bin/console.php migrate:rollback && docker compose exec app php bin/console.php migrate`
Expected: `Rolled back: ...000003...` e `Migrated: ...000003...`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_10_08_000003_create_institutions_tables.php
git commit -m "Cria as tabelas de instituições e vínculos"
```

---

### Task 2: Regras puras — código de convite e escopo do professor

**Files:**
- Create: `app/Support/InviteCode.php`, `app/Support/InstitutionScope.php`
- Test: `tests/Unit/InviteCodeTest.php`, `tests/Unit/InstitutionScopeTest.php`

**Interfaces:**
- Produces: `InviteCode::generate(): string`, `InviteCode::normalize(string $input): ?string`, `InviteCode::PATTERN`; `InstitutionScope::canSeeStudent(Role $viewerRole, array $viewerInstitutionIds, ?int $studentInstitutionId): bool`.

- [ ] **Step 1: Testes que falham**

`tests/Unit/InviteCodeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\InviteCode;

it('generates XXXX-XXXX codes without look-alike characters', function () {
    foreach (range(1, 200) as $_) {
        $code = InviteCode::generate();
        expect($code)->toMatch(InviteCode::PATTERN)
            ->and($code)->not->toMatch('/[01IO]/');
    }
});

it('normalizes what a student types', function () {
    expect(InviteCode::normalize(' abcd-ef23 '))->toBe('ABCD-EF23')
        ->and(InviteCode::normalize('abcdef23'))->toBe('ABCD-EF23')
        ->and(InviteCode::normalize('AB CD EF 23'))->toBe('ABCD-EF23')
        ->and(InviteCode::normalize(''))->toBeNull()
        ->and(InviteCode::normalize('ABCD-EF2'))->toBeNull()
        ->and(InviteCode::normalize('ABCD-EF20'))->toBeNull()
        ->and(InviteCode::normalize("ABCD-EF23'; DROP"))->toBeNull();
});
```

`tests/Unit/InstitutionScopeTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\InstitutionScope;
use App\Support\Role;

it('lets the admin see every student, with or without institution', function () {
    expect(InstitutionScope::canSeeStudent(Role::Admin, [], null))->toBeTrue()
        ->and(InstitutionScope::canSeeStudent(Role::Admin, [], 4))->toBeTrue();
});

it('lets a professor see only students of a shared institution', function () {
    expect(InstitutionScope::canSeeStudent(Role::Professor, [1, 4], 4))->toBeTrue()
        ->and(InstitutionScope::canSeeStudent(Role::Professor, [1, 4], 2))->toBeFalse()
        ->and(InstitutionScope::canSeeStudent(Role::Professor, [1, 4], null))->toBeFalse()
        ->and(InstitutionScope::canSeeStudent(Role::Professor, [], 4))->toBeFalse();
});

it('never lets a student manage students', function () {
    expect(InstitutionScope::canSeeStudent(Role::Aluno, [4], 4))->toBeFalse();
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `vendor/bin/pest tests/Unit/InviteCodeTest.php tests/Unit/InstitutionScopeTest.php`
Expected: FAIL — `Class "App\Support\InviteCode" not found`.

- [ ] **Step 3: Implementar**

`app/Support/InviteCode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Código que o aluno digita no cadastro pra já entrar na instituição (ver RegisterAction).
 * Sem 0/O e 1/I: é lido no quadro e digitado à mão.
 */
final class InviteCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const PATTERN = '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/';

    public static function generate(): string
    {
        $chars = '';
        for ($i = 0; $i < 8; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 4) . '-' . substr($chars, 4);
    }

    /** Aceita minúsculas, espaços e hífen opcional; devolve null se não for um código válido. */
    public static function normalize(string $input): ?string
    {
        $compact = strtoupper((string) preg_replace('/[\s-]+/', '', $input));
        if (strlen($compact) !== 8) {
            return null;
        }
        $code = substr($compact, 0, 4) . '-' . substr($compact, 4);

        return preg_match(self::PATTERN, $code) === 1 ? $code : null;
    }
}
```

`app/Support/InstitutionScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Quem enxerga qual aluno em /professor/alunos (e nas ações sobre ele): admin vê todos;
 * professor só os de uma instituição em comum; aluno sem instituição só o admin vê.
 */
final class InstitutionScope
{
    /** @param list<int> $viewerInstitutionIds */
    public static function canSeeStudent(Role $viewerRole, array $viewerInstitutionIds, ?int $studentInstitutionId): bool
    {
        return match ($viewerRole) {
            Role::Admin => true,
            Role::Professor => $studentInstitutionId !== null && in_array($studentInstitutionId, $viewerInstitutionIds, true),
            default => false,
        };
    }
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `vendor/bin/pest tests/Unit/InviteCodeTest.php tests/Unit/InstitutionScopeTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/InviteCode.php app/Support/InstitutionScope.php tests/Unit/InviteCodeTest.php tests/Unit/InstitutionScopeTest.php
git commit -m "Código de convite e regra de escopo do professor por instituição"
```

---

### Task 3: Arquivo de excluídos conhece instituições e vínculos

**Files:**
- Modify: `app/Support/ArchiveGraph.php`, `app/Support/ArchiveRestoreChecks.php`, `app/Services/Archiver.php`, `app/Models/DeletedModel.php`
- Test: `tests/Unit/ArchiveGraphTest.php`, `tests/Unit/ArchiveRestoreChecksTest.php`

**Interfaces:**
- Produces: modelos `institution` (tabela `institutions`, filho `institution_member` por `institution_id`) e `institution_member` (tabela `institution_members`); `user` passa a ter filho `institution_member` por `user_id`. `DeletedModel::isReserved('institution_name'|'invite_code', ...)`. Rótulo de vínculo: `"<nome do usuário> → <nome da instituição> (<papel>)"`.

- [ ] **Step 1: Atualizar os testes (falham)**

Em `tests/Unit/ArchiveGraphTest.php`, trocar o primeiro teste e o teste de filhos por:

```php
it('knows the archivable models and their tables', function () {
    expect(ArchiveGraph::MODELS)->toBe(['user', 'schema', 'saved_query', 'er_diagram', 'institution', 'institution_member'])
        ->and(ArchiveGraph::table('user'))->toBe('users')
        ->and(ArchiveGraph::table('schema'))->toBe('schemas_criados')
        ->and(ArchiveGraph::table('saved_query'))->toBe('saved_queries')
        ->and(ArchiveGraph::table('er_diagram'))->toBe('er_diagrams')
        ->and(ArchiveGraph::table('institution'))->toBe('institutions')
        ->and(ArchiveGraph::table('institution_member'))->toBe('institution_members')
        ->and(ArchiveGraph::isKnown('remember_token'))->toBeFalse();
});
```

```php
it('takes dependents along: a user\'s data and memberships, an institution\'s memberships', function () {
    expect(ArchiveGraph::children('user'))->toBe(['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id', 'institution_member' => 'user_id'])
        ->and(ArchiveGraph::children('institution'))->toBe(['institution_member' => 'institution_id'])
        ->and(ArchiveGraph::children('schema'))->toBe([])
        ->and(ArchiveGraph::label('institution', ['name' => 'Escola Azul']))->toBe('Escola Azul')
        ->and(ArchiveGraph::typeLabel('institution_member'))->toBe('Vínculo com instituição');
});
```

Adicionar ao fim de `tests/Unit/ArchiveRestoreChecksTest.php`:

```php
it('checks name and code of an institution and the target of a membership', function () {
    $checks = ArchiveRestoreChecks::for([
        archivedItem('institution_member', 5, ['institution_id' => 2, 'user_id' => 9, 'role' => 'aluno']),
    ]);

    $sqls = array_column($checks, 'sql');
    expect($sqls)->toContain('SELECT 1 FROM institutions WHERE id = ?')
        ->and($sqls)->toContain('SELECT 1 FROM institution_members WHERE student_user_id = ?');

    $withInstitution = ArchiveRestoreChecks::for([
        archivedItem('institution', 2, ['name' => 'Escola Azul', 'invite_code' => 'ABCD-EF23']),
        archivedItem('institution_member', 5, ['institution_id' => 2, 'user_id' => 9, 'role' => 'professor']),
    ]);
    $sqls = array_column($withInstitution, 'sql');
    expect($sqls)->toContain('SELECT 1 FROM institutions WHERE name = ?')
        ->and($sqls)->toContain('SELECT 1 FROM institutions WHERE invite_code = ?')
        ->and($sqls)->not->toContain('SELECT 1 FROM institutions WHERE id = ?')
        ->and($sqls)->not->toContain('SELECT 1 FROM institution_members WHERE student_user_id = ?');
});
```

Run: `vendor/bin/pest tests/Unit/ArchiveGraphTest.php tests/Unit/ArchiveRestoreChecksTest.php`
Expected: FAIL (modelos novos desconhecidos).

- [ ] **Step 2: `ArchiveGraph`**

Em `app/Support/ArchiveGraph.php`:
- `MODELS`: `['user', 'schema', 'saved_query', 'er_diagram', 'institution', 'institution_member']`.
- `TABLES`: acrescentar `'institution' => 'institutions', 'institution_member' => 'institution_members',`.
- `CHILDREN`: `'user' => ['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id', 'institution_member' => 'user_id'], 'institution' => ['institution_member' => 'institution_id'],`.
- `TYPE_LABELS`: acrescentar `'institution' => 'Instituição', 'institution_member' => 'Vínculo com instituição',`.
- `label()`: acrescentar os braços

```php
            'institution' => (string) ($row['name'] ?? '?'),
            'institution_member' => sprintf('Usuário #%s → instituição #%s (%s)', $row['user_id'] ?? '?', $row['institution_id'] ?? '?', $row['role'] ?? '?'),
```

- [ ] **Step 3: `ArchiveRestoreChecks`**

Em `app/Support/ArchiveRestoreChecks.php`, no começo de `for()`, junto de `$usersInBatch`, montar também `$institutionsInBatch` (ids dos itens `institution`). Dentro do laço, depois do bloco `schema`, acrescentar:

```php
            if ($item['model'] === 'institution') {
                $checks[] = self::absent('SELECT 1 FROM institutions WHERE name = ?', [(string) $v['name']], "Já existe uma instituição chamada {$v['name']}.");
                if (($v['invite_code'] ?? null) !== null) {
                    $checks[] = self::absent('SELECT 1 FROM institutions WHERE invite_code = ?', [(string) $v['invite_code']], "O código {$v['invite_code']} já é de outra instituição.");
                }
            }

            if ($item['model'] === 'institution_member') {
                $institutionId = (int) $v['institution_id'];
                if (!in_array($institutionId, $institutionsInBatch, true)) {
                    $checks[] = [
                        'sql' => 'SELECT 1 FROM institutions WHERE id = ?',
                        'params' => [$institutionId],
                        'expect' => 'present',
                        'message' => "{$item['label']}: a instituição #{$institutionId} também está excluída — restaure a instituição primeiro.",
                    ];
                    if (($v['role'] ?? '') === 'aluno') {
                        $checks[] = self::absent('SELECT 1 FROM institution_members WHERE student_user_id = ?', [(int) $v['user_id']], "{$item['label']}: o aluno já está em outra instituição.");
                    }
                }
            }
```

(Num lote de instituição, os alunos voltam para ela mesma; o UNIQUE do MySQL ainda barra se um deles entrou em outra no meio-tempo, e a restauração falha inteira com mensagem genérica — aceitável e auditado.)

- [ ] **Step 4: `Archiver` — rótulo de vínculo e colunas geradas**

Em `app/Services/Archiver.php`:

1. No `archive()`, trocar a chamada `ArchiveGraph::label($item['model'], $item['row'])` por `self::labelFor($pdo, $item['model'], $item['row'])` e adicionar o método:

```php
    /** @param array<string, mixed> $row */
    private static function labelFor(PDO $pdo, string $model, array $row): string
    {
        if ($model !== 'institution_member') {
            return ArchiveGraph::label($model, $row);
        }
        $stmt = $pdo->prepare('SELECT u.name, i.name FROM users u, institutions i WHERE u.id = ? AND i.id = ?');
        $stmt->execute([(int) $row['user_id'], (int) $row['institution_id']]);
        $names = $stmt->fetch(PDO::FETCH_NUM);

        return $names === false
            ? ArchiveGraph::label($model, $row)
            : mb_substr("{$names[0]} → {$names[1]} ({$row['role']})", 0, 200);
    }
```

2. Em `insertRow()`, trocar a consulta de colunas por uma que ignore colunas geradas (o MySQL recusa valor nelas):

```php
        $existing = $pdo->prepare(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%'",
        );
```

e no docblock: `Colunas geradas (ex.: institution_members.student_user_id) e colunas que deixaram de existir são ignoradas.`

- [ ] **Step 5: Reservas**

Em `app/Models/DeletedModel.php`, em `RESERVABLE`, acrescentar:

```php
        'institution_name' => ['institution', '$.name'],
        'invite_code' => ['institution', '$.invite_code'],
```

- [ ] **Step 6: Rodar**

Run: `vendor/bin/pest && composer test:integration && vendor/bin/php-cs-fixer fix --dry-run`
Expected: tudo verde (o arquivo da etapa 1 continua funcionando).

- [ ] **Step 7: Commit**

```bash
git add app/Support/ArchiveGraph.php app/Support/ArchiveRestoreChecks.php app/Services/Archiver.php app/Models/DeletedModel.php tests/Unit/ArchiveGraphTest.php tests/Unit/ArchiveRestoreChecksTest.php
git commit -m "Arquivo de excluídos passa a cobrir instituições e vínculos"
```

---

### Task 4: Modelos e `InstitutionManager`

**Files:**
- Create: `app/Models/Entities/Institution.php`, `app/Models/Institution.php`, `app/Models/InstitutionMember.php`, `app/Services/InstitutionException.php`, `app/Services/InstitutionManager.php`
- Modify: `tests/Pest.php` (limpeza das instituições de teste)
- Test: `tests/Integration/InstitutionsTest.php`

**Interfaces:**
- Consumes: `InviteCode` (Task 2), `Archiver::archive()` (etapa 1, Task 3 desta), `DeletedModel::isReserved()`.
- Produces:
  - `Entities\Institution { int $id, string $name, ?string $inviteCode, DateTimeImmutable $createdAt, int $professors, int $students }`.
  - `Institution::all(): list<Entities\Institution>`, `Institution::find(int $id): ?Entities\Institution`, `Institution::findByInviteCode(string $code): ?Entities\Institution`, `Institution::nameTaken(string $name, int $exceptId = 0): bool`, `Institution::inviteCodeTaken(string $code): bool`, `Institution::create(string $name, string $inviteCode): int`, `Institution::rename(int $id, string $name): void`, `Institution::setInviteCode(int $id, ?string $code): void`.
  - `InstitutionMember::forInstitution(int $institutionId): list<array{id: int, user_id: int, role: string, name: string, email: string, mysql_login: string}>`, `InstitutionMember::forUser(int $userId): list<array{id: int, institution_id: int, role: string}>`, `InstitutionMember::institutionIdsOf(int $userId): list<int>`, `InstitutionMember::institutionOfStudent(int $userId): ?int`, `InstitutionMember::studentInstitutions(): array<int, array{id: int, name: string}>` (user_id => instituição), `InstitutionMember::studentsWithoutInstitution(): int`, `InstitutionMember::find(int $id): ?array{id: int, institution_id: int, user_id: int, role: string}`, `InstitutionMember::add(int $institutionId, int $userId, string $role): int`, `InstitutionMember::setRoleForUser(int $userId, string $role): void`.
  - `InstitutionException extends RuntimeException` (mensagem segura).
  - `InstitutionManager::create(string $name): int`, `rename(int $id, string $name): void`, `regenerateCode(int $id): string`, `disableCode(int $id): void`, `addMember(int $institutionId, string $identifier): App\Models\Entities\User`, `removeMember(int $memberId): void`, `delete(int $id): void`, `syncRoleChange(App\Models\Entities\User $user, App\Support\Role $newRole): void`.
  - Helper de teste: `integrationInstitution(string $suffix = ''): int` (cria `it-inst-<hex>`).

- [ ] **Step 1: Helpers de teste**

Em `tests/Pest.php`, adicionar `use App\Services\InstitutionManager;` aos `use` do topo e, depois de `integrationSchema()`:

```php
function integrationInstitution(): int
{
    return InstitutionManager::create('it-inst-' . bin2hex(random_bytes(4)));
}
```

E em `cleanupIntegrationData()`, antes do `DELETE FROM users ...`, acrescentar:

```php
    $pdo->exec("DELETE FROM institutions WHERE name REGEXP '^it-inst-[0-9a-f]{8}$'");
```

e trocar o REGEXP do `DELETE FROM deleted_models` por `'^(Integração [0-9a-f]{8} |it[0-9a-f]{8}__|q-it |it-inst-[0-9a-f]{8})'` (pega também os vínculos, cujo rótulo começa com o nome do usuário de teste).

- [ ] **Step 2: Teste de integração que falha**

`tests/Integration/InstitutionsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Core\Database;
use App\Models\DeletedModel;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Services\Archiver;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use App\Support\InviteCode;
use App\Support\Role;

beforeEach(fn () => requiresDatabase());
afterEach(fn () => getenv('INTEGRATION') === '1' && cleanupIntegrationData());

it('creates an institution with a fresh invite code and refuses a duplicate name', function () {
    $id = integrationInstitution();
    $institution = Institution::find($id);

    expect($institution->inviteCode)->toMatch(InviteCode::PATTERN)
        ->and(Institution::findByInviteCode($institution->inviteCode)?->id)->toBe($id);

    expect(fn () => InstitutionManager::create($institution->name))->toThrow(InstitutionException::class, 'Já existe');
});

it('lets a professor be in two institutions but a student in only one', function () {
    [$a, $b] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    $student = integrationUser();

    InstitutionManager::addMember($a, $professor->email);
    InstitutionManager::addMember($b, $professor->mysqlLogin);
    InstitutionManager::addMember($a, $student->email);

    expect(InstitutionMember::institutionIdsOf($professor->id))->toEqualCanonicalizing([$a, $b])
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBe($a);

    expect(fn () => InstitutionManager::addMember($b, $student->email))->toThrow(InstitutionException::class, 'já está na instituição');

    // A regra vale no próprio MySQL, mesmo sem passar pelo InstitutionManager.
    expect(fn () => InstitutionMember::add($b, $student->id, 'aluno'))->toThrow(PDOException::class);
});

it('refuses admins and unknown people as members', function () {
    $id = integrationInstitution();
    $admin = integrationUser(Role::Admin);

    expect(fn () => InstitutionManager::addMember($id, $admin->email))->toThrow(InstitutionException::class, 'Admins')
        ->and(fn () => InstitutionManager::addMember($id, 'ninguem@example.test'))->toThrow(InstitutionException::class, 'Nenhuma conta');
});

it('archives an institution with its memberships and restores everything', function () {
    $id = integrationInstitution();
    $code = Institution::find($id)->inviteCode;
    $student = integrationUser();
    InstitutionManager::addMember($id, $student->email);

    InstitutionManager::delete($id);

    expect(Institution::find($id))->toBeNull()
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBeNull()
        ->and(Institution::nameTaken(DeletedModel::itemsOfBatch(batchOf('institution', $id))[0]['label']))->toBeTrue()
        ->and(Institution::inviteCodeTaken($code))->toBeTrue();

    Archiver::restore(batchOf('institution', $id));

    expect(Institution::find($id)?->inviteCode)->toBe($code)
        ->and(InstitutionMember::institutionOfStudent($student->id))->toBe($id);
});

it('archives a single membership when it is removed', function () {
    $id = integrationInstitution();
    $student = integrationUser();
    InstitutionManager::addMember($id, $student->email);
    $memberId = InstitutionMember::forInstitution($id)[0]['id'];

    InstitutionManager::removeMember($memberId);

    $batch = batchOf('institution_member', $memberId);
    expect(InstitutionMember::institutionOfStudent($student->id))->toBeNull()
        ->and(DeletedModel::itemsOfBatch($batch)[0]['label'])->toBe("{$student->name} → " . Institution::find($id)->name . ' (aluno)');
});

it('keeps memberships consistent when the role changes', function () {
    [$a, $b] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    InstitutionManager::addMember($a, $professor->email);
    InstitutionManager::addMember($b, $professor->email);

    expect(fn () => InstitutionManager::syncRoleChange($professor, Role::Aluno))->toThrow(InstitutionException::class, 'vínculos');

    InstitutionManager::syncRoleChange($professor, Role::Admin);
    expect(InstitutionMember::institutionIdsOf($professor->id))->toBe([]);

    $student = integrationUser();
    InstitutionManager::addMember($a, $student->email);
    InstitutionManager::syncRoleChange($student, Role::Professor);
    expect(InstitutionMember::forUser($student->id)[0]['role'])->toBe('professor');
});

function batchOf(string $model, int $id): string
{
    $stmt = Database::connection()->prepare('SELECT batch_id FROM deleted_models WHERE model = ? AND model_id = ? AND is_root = 1 ORDER BY id DESC LIMIT 1');
    $stmt->execute([$model, $id]);

    return (string) $stmt->fetchColumn();
}
```

Run: `composer test:integration -- --filter=Institutions`
Expected: FAIL — `Class "App\Services\InstitutionManager" not found`.

- [ ] **Step 3: Entidade e modelos**

`app/Models/Entities/Institution.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models\Entities;

use DateTimeImmutable;

final readonly class Institution
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $inviteCode,
        public DateTimeImmutable $createdAt,
        public int $professors = 0,
        public int $students = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            inviteCode: $row['invite_code'] !== null ? (string) $row['invite_code'] : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
            professors: (int) ($row['professors'] ?? 0),
            students: (int) ($row['students'] ?? 0),
        );
    }
}
```

`app/Models/Institution.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Models\Entities\Institution as InstitutionEntity;

/** Tabela institutions. Escrita passa por App\Services\InstitutionManager. */
final class Institution
{
    private const WITH_COUNTS = "SELECT i.*,
            (SELECT COUNT(*) FROM institution_members m WHERE m.institution_id = i.id AND m.role = 'professor') AS professors,
            (SELECT COUNT(*) FROM institution_members m WHERE m.institution_id = i.id AND m.role = 'aluno') AS students
        FROM institutions i";

    /** @return list<InstitutionEntity> */
    public static function all(): array
    {
        return array_map(InstitutionEntity::fromRow(...), Database::connection()->query(self::WITH_COUNTS . ' ORDER BY i.name')->fetchAll());
    }

    public static function find(int $id): ?InstitutionEntity
    {
        $stmt = Database::connection()->prepare(self::WITH_COUNTS . ' WHERE i.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : InstitutionEntity::fromRow($row);
    }

    public static function findByInviteCode(string $code): ?InstitutionEntity
    {
        $stmt = Database::connection()->prepare(self::WITH_COUNTS . ' WHERE i.invite_code = ?');
        $stmt->execute([$code]);
        $row = $stmt->fetch();

        return $row === false ? null : InstitutionEntity::fromRow($row);
    }

    /** Nome em uso por outra instituição — ou reservado por uma que está em Dados excluídos. */
    public static function nameTaken(string $name, int $exceptId = 0): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM institutions WHERE name = ? AND id <> ?');
        $stmt->execute([$name, $exceptId]);

        return $stmt->fetch() !== false || DeletedModel::isReserved('institution_name', $name);
    }

    public static function inviteCodeTaken(string $code): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM institutions WHERE invite_code = ?');
        $stmt->execute([$code]);

        return $stmt->fetch() !== false || DeletedModel::isReserved('invite_code', $code);
    }

    public static function create(string $name, string $inviteCode): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO institutions (name, invite_code) VALUES (?, ?)')->execute([$name, $inviteCode]);

        return (int) $pdo->lastInsertId();
    }

    public static function rename(int $id, string $name): void
    {
        Database::connection()->prepare('UPDATE institutions SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public static function setInviteCode(int $id, ?string $code): void
    {
        Database::connection()->prepare('UPDATE institutions SET invite_code = ? WHERE id = ?')->execute([$code, $id]);
    }
}
```

`app/Models/InstitutionMember.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use PDO;

/** Tabela institution_members (professor em várias, aluno em uma — ver a migration). */
final class InstitutionMember
{
    /** @return list<array{id: int, user_id: int, role: string, name: string, email: string, mysql_login: string}> */
    public static function forInstitution(int $institutionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.id, m.user_id, m.role, u.name, u.email, u.mysql_login
             FROM institution_members m INNER JOIN users u ON u.id = m.user_id
             WHERE m.institution_id = ? ORDER BY u.name',
        );
        $stmt->execute([$institutionId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'user_id' => (int) $r['user_id']] + $r, $stmt->fetchAll());
    }

    /** @return list<array{id: int, institution_id: int, role: string}> */
    public static function forUser(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, institution_id, role FROM institution_members WHERE user_id = ? ORDER BY id');
        $stmt->execute([$userId]);

        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'institution_id' => (int) $r['institution_id'], 'role' => (string) $r['role']], $stmt->fetchAll());
    }

    /** @return list<int> */
    public static function institutionIdsOf(int $userId): array
    {
        return array_column(self::forUser($userId), 'institution_id');
    }

    public static function institutionOfStudent(int $userId): ?int
    {
        $stmt = Database::connection()->prepare('SELECT institution_id FROM institution_members WHERE student_user_id = ?');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<int, array{id: int, name: string}> user_id do aluno => instituição */
    public static function studentInstitutions(): array
    {
        $rows = Database::connection()->query(
            "SELECT m.user_id, i.id, i.name FROM institution_members m INNER JOIN institutions i ON i.id = m.institution_id WHERE m.role = 'aluno'",
        )->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $map[(int) $r['user_id']] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
        }

        return $map;
    }

    public static function studentsWithoutInstitution(): int
    {
        return (int) Database::connection()->query(
            "SELECT COUNT(*) FROM users u WHERE u.role = 'aluno' AND NOT EXISTS (SELECT 1 FROM institution_members m WHERE m.user_id = u.id)",
        )->fetchColumn();
    }

    /** @return ?array{id: int, institution_id: int, user_id: int, role: string} */
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, institution_id, user_id, role FROM institution_members WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : ['id' => (int) $r['id'], 'institution_id' => (int) $r['institution_id'], 'user_id' => (int) $r['user_id'], 'role' => (string) $r['role']];
    }

    public static function add(int $institutionId, int $userId, string $role): int
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO institution_members (institution_id, user_id, role) VALUES (?, ?, ?)')->execute([$institutionId, $userId, $role]);

        return (int) $pdo->lastInsertId();
    }

    public static function setRoleForUser(int $userId, string $role): void
    {
        Database::connection()->prepare('UPDATE institution_members SET role = ? WHERE user_id = ?')->execute([$role, $userId]);
    }
}
```

- [ ] **Step 4: Exceção e serviço**

`app/Services/InstitutionException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Regra de negócio de instituição violada — mensagem escrita aqui, segura pra mostrar na tela. */
final class InstitutionException extends RuntimeException
{
}
```

`app/Services/InstitutionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Entities\User as UserEntity;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\User;
use App\Support\InviteCode;
use App\Support\Role;

/**
 * Escrita de instituições e vínculos (só admin chama — ver app/Actions/Institution). Remover
 * vínculo e excluir instituição passam pelo Archiver: nada é apagado direto.
 */
final class InstitutionManager
{
    private const MAX_NAME = 120;

    public static function create(string $name): int
    {
        $name = self::validName($name, 0);
        $id = Institution::create($name, self::freshCode());
        AuditLog::record('institution.created', 'institution', $id, ['nome' => $name]);

        return $id;
    }

    public static function rename(int $id, string $name): void
    {
        $current = self::findOrFail($id);
        $name = self::validName($name, $id);
        Institution::rename($id, $name);
        AuditLog::record('institution.renamed', 'institution', $id, ['de' => $current->name, 'para' => $name]);
    }

    public static function regenerateCode(int $id): string
    {
        self::findOrFail($id);
        $code = self::freshCode();
        Institution::setInviteCode($id, $code);
        AuditLog::record('institution.code_regenerated', 'institution', $id);

        return $code;
    }

    public static function disableCode(int $id): void
    {
        self::findOrFail($id);
        Institution::setInviteCode($id, null);
        AuditLog::record('institution.code_disabled', 'institution', $id);
    }

    /** $identifier: e-mail ou username. O papel do vínculo vem da conta. */
    public static function addMember(int $institutionId, string $identifier): UserEntity
    {
        $institution = self::findOrFail($institutionId);
        $user = User::findByEmailOrUsername(trim($identifier))
            ?? throw new InstitutionException('Nenhuma conta com esse e-mail ou username.');

        if ($user->role === Role::Admin) {
            throw new InstitutionException('Admins já veem todas as instituições — não precisam de vínculo.');
        }
        if (in_array($institutionId, InstitutionMember::institutionIdsOf($user->id), true)) {
            throw new InstitutionException("{$user->name} já está nesta instituição.");
        }
        if ($user->role === Role::Aluno && ($current = InstitutionMember::institutionOfStudent($user->id)) !== null) {
            $other = Institution::find($current)?->name ?? "#{$current}";
            throw new InstitutionException("{$user->name} já está na instituição {$other}. Remova de lá antes (aluno fica em uma só).");
        }

        InstitutionMember::add($institutionId, $user->id, $user->role->value);
        AuditLog::record('institution.member_added', 'institution', $institutionId, ['usuario' => $user->email, 'papel' => $user->role->value, 'instituicao' => $institution->name]);

        return $user;
    }

    public static function removeMember(int $memberId): void
    {
        InstitutionMember::find($memberId) ?? throw new InstitutionException('Vínculo não encontrado.');
        Archiver::archive('institution_member', $memberId, 'institution.member_removed');
    }

    public static function delete(int $id): void
    {
        $institution = self::findOrFail($id);
        Archiver::archive('institution', $id, 'institution.deleted', ['nome' => $institution->name]);
    }

    /**
     * Chamado ANTES de trocar o papel da conta (UpdateAdminUserRoleAction): aluno só pode ter
     * um vínculo; admin não tem vínculo (os dele vão pro arquivo).
     */
    public static function syncRoleChange(UserEntity $user, Role $newRole): void
    {
        $memberships = InstitutionMember::forUser($user->id);
        if ($memberships === []) {
            return;
        }

        if ($newRole === Role::Admin) {
            foreach ($memberships as $m) {
                Archiver::archive('institution_member', $m['id'], 'institution.member_removed', ['motivo' => 'promovido a admin']);
            }

            return;
        }
        if ($newRole === Role::Aluno && count($memberships) > 1) {
            throw new InstitutionException("{$user->name} está em " . count($memberships) . ' instituições; aluno fica em uma só. Remova os vínculos extras antes de trocar o papel.');
        }

        InstitutionMember::setRoleForUser($user->id, $newRole->value);
    }

    private static function findOrFail(int $id): \App\Models\Entities\Institution
    {
        return Institution::find($id) ?? throw new InstitutionException('Instituição não encontrada.');
    }

    private static function validName(string $name, int $exceptId): string
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > self::MAX_NAME) {
            throw new InstitutionException('Informe um nome entre 2 e ' . self::MAX_NAME . ' caracteres.');
        }
        if (Institution::nameTaken($name, $exceptId)) {
            throw new InstitutionException("Já existe uma instituição chamada {$name} (ou ela está em Dados excluídos).");
        }

        return $name;
    }

    private static function freshCode(): string
    {
        do {
            $code = InviteCode::generate();
        } while (Institution::inviteCodeTaken($code));

        return $code;
    }
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `composer test:integration && vendor/bin/pest && vendor/bin/php-cs-fixer fix --dry-run`
Expected: tudo verde.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Entities/Institution.php app/Models/Institution.php app/Models/InstitutionMember.php app/Services/InstitutionException.php app/Services/InstitutionManager.php tests/Pest.php tests/Integration/InstitutionsTest.php
git commit -m "Instituições e vínculos: modelos e InstitutionManager"
```

---

### Task 5: Telas do admin

**Files:**
- Create: `app/Actions/Institution/*.php` (8 actions), `app/Views/admin/institutions.twig`, `app/Views/admin/institution.twig`
- Modify: `public/index.php`, `app/Views/partials/nav-links.twig`, `app/Views/admin/index.twig`

**Interfaces:**
- Consumes: `Institution`, `InstitutionMember`, `InstitutionManager`, `InstitutionException` (Task 4).
- Produces: rotas `GET/POST /admin/instituicoes`, `GET/POST /admin/instituicoes/{id}`, `POST /admin/instituicoes/{id}/codigo`, `POST /admin/instituicoes/{id}/membros`, `POST /admin/instituicoes/{id}/membros/{member}/remover`, `POST /admin/instituicoes/{id}/excluir`.

- [ ] **Step 1: Actions**

`app/Actions/Institution/IndexInstitutionsAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Models\Institution;
use App\Models\InstitutionMember;

/** GET /admin/instituicoes. */
final class IndexInstitutionsAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $this->render('admin/institutions', [
            'pageTitle' => 'Instituições',
            'institutions' => Institution::all(),
            'studentsWithoutInstitution' => InstitutionMember::studentsWithoutInstitution(),
        ]);
    }
}
```

`app/Actions/Institution/StoreInstitutionAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes — form comum (boosted): cria e abre a página da instituição. */
final class StoreInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        try {
            $id = InstitutionManager::create((string) ($_POST['name'] ?? ''));
            $this->respond(true, 'Instituição criada. Compartilhe o código de convite com os alunos.', "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), '/admin/instituicoes');
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('criar a instituição', $e), '/admin/instituicoes');
        }
    }
}
```

`app/Actions/Institution/ShowInstitutionAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Core\View;
use App\Models\Institution;
use App\Models\InstitutionMember;

/** GET /admin/instituicoes/{id}. */
final class ShowInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();

        $institution = Institution::find((int) ($params['id'] ?? 0));
        if ($institution === null) {
            http_response_code(404);
            echo View::render('errors/404');

            return;
        }

        $members = InstitutionMember::forInstitution($institution->id);

        $this->render('admin/institution', [
            'pageTitle' => $institution->name,
            'institution' => $institution,
            'professors' => array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'professor')),
            'students' => array_values(array_filter($members, static fn (array $m): bool => $m['role'] === 'aluno')),
        ]);
    }
}
```

`app/Actions/Institution/UpdateInstitutionAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id} — renomear. */
final class UpdateInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            InstitutionManager::rename($id, (string) ($_POST['name'] ?? ''));
            $this->respond(true, 'Nome atualizado.', "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('renomear a instituição', $e), "/admin/instituicoes/{$id}");
        }
    }
}
```

`app/Actions/Institution/InstitutionCodeAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/codigo — acao=gerar|desativar. */
final class InstitutionCodeAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            if (($_POST['acao'] ?? '') === 'desativar') {
                InstitutionManager::disableCode($id);
                $this->respond(true, 'Código desativado: ninguém mais entra por ele no cadastro.', "/admin/instituicoes/{$id}");
            }
            $code = InstitutionManager::regenerateCode($id);
            $this->respond(true, "Novo código: {$code}. O anterior deixou de valer.", "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('trocar o código', $e), "/admin/instituicoes/{$id}");
        }
    }
}
```

`app/Actions/Institution/AddInstitutionMemberAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/membros — identificador = e-mail ou username. */
final class AddInstitutionMemberAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            $user = InstitutionManager::addMember($id, (string) ($_POST['identificador'] ?? ''));
            $this->respond(true, "{$user->name} vinculado como {$user->role->label()}.", "/admin/instituicoes/{$id}");
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('vincular a pessoa', $e), "/admin/instituicoes/{$id}");
        }
    }
}
```

`app/Actions/Institution/RemoveInstitutionMemberAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Models\InstitutionMember;
use App\Services\ArchiveException;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/membros/{member}/remover — o vínculo vai para Dados excluídos. */
final class RemoveInstitutionMemberAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);
        $member = InstitutionMember::find((int) ($params['member'] ?? 0));
        if ($member === null || $member['institution_id'] !== $id) {
            $this->respond(false, 'Vínculo não encontrado.', "/admin/instituicoes/{$id}");
        }

        try {
            InstitutionManager::removeMember($member['id']);
            $this->respond(true, 'Vínculo removido (fica em Dados excluídos).', "/admin/instituicoes/{$id}");
        } catch (InstitutionException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('remover o vínculo', $e), "/admin/instituicoes/{$id}");
        }
    }
}
```

`app/Actions/Institution/DestroyInstitutionAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Institution;

use App\Core\Action;
use App\Core\Auth;
use App\Services\ArchiveException;
use App\Services\InstitutionException;
use App\Services\InstitutionManager;
use Throwable;

/** POST /admin/instituicoes/{id}/excluir — vai para Dados excluídos com os vínculos. */
final class DestroyInstitutionAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        $id = (int) ($params['id'] ?? 0);

        try {
            InstitutionManager::delete($id);
            $this->respond(true, 'Instituição movida para Dados excluídos (com os vínculos). As contas continuam ativas.', '/admin/instituicoes');
        } catch (InstitutionException|ArchiveException $e) {
            $this->respond(false, $e->getMessage(), "/admin/instituicoes/{$id}");
        } catch (Throwable $e) {
            $this->respond(false, $this->genericError('excluir a instituição', $e), "/admin/instituicoes/{$id}");
        }
    }
}
```

- [ ] **Step 2: Views**

`app/Views/admin/institutions.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% block content %}
    <div class="mb-8">
        <h1 class="text-2xl font-semibold text-slate-900">Instituições</h1>
        <p class="mt-1 text-sm text-slate-500">
            Escolas e faculdades que usam o lab. Professores podem estar em várias; cada aluno fica em uma.
            Professor só enxerga os alunos das instituições dele.
        </p>
    </div>

    {% if studentsWithoutInstitution > 0 %}
        <a href="/professor/alunos?instituicao=sem" class="mb-6 block rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 hover:bg-amber-100">
            {{ studentsWithoutInstitution }} aluno{{ studentsWithoutInstitution == 1 ? '' : 's' }} sem instituição — nenhum professor enxerga. Ver e vincular →
        </a>
    {% endif %}

    <form method="post" action="/admin/instituicoes" class="card mb-6 grid gap-3 sm:grid-cols-[1fr_auto]">
        {{ csrf_field() }}
        <input type="text" name="name" required minlength="2" maxlength="120" placeholder="Nome da nova instituição" class="field-input">
        <button type="submit" class="btn-primary">Criar instituição</button>
    </form>

    {% if institutions is empty %}
        <div class="card py-10 text-center text-sm text-slate-500">Nenhuma instituição ainda.</div>
    {% else %}
        <div class="grid gap-4 sm:grid-cols-2">
            {% for i in institutions %}
                <a href="/admin/instituicoes/{{ i.id }}" class="card transition-shadow hover:shadow-md">
                    <p class="font-semibold text-slate-900">{{ i.name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ i.professors }} professor{{ i.professors == 1 ? '' : 'es' }} · {{ i.students }} aluno{{ i.students == 1 ? '' : 's' }}</p>
                    <p class="mt-2 font-mono text-xs {{ i.inviteCode ? 'text-indigo-600' : 'text-slate-400' }}">{{ i.inviteCode ?: 'código desativado' }}</p>
                </a>
            {% endfor %}
        </div>
    {% endif %}
{% endblock %}
```

`app/Views/admin/institution.twig`:

```twig
{% extends 'layouts/app.twig' %}

{% macro members(list, institution, title, empty) %}
    <div class="card">
        <h2 class="mb-3 text-base font-semibold text-slate-900">{{ title }} <span class="text-sm font-normal text-slate-400">({{ list|length }})</span></h2>
        {% if list is empty %}
            <p class="text-sm text-slate-500">{{ empty }}</p>
        {% else %}
            <ul class="divide-y divide-slate-100 text-sm">
                {% for m in list %}
                    <li class="flex items-center justify-between gap-3 py-2" data-row>
                        <span class="min-w-0">
                            <span class="font-medium text-slate-800">{{ m.name }}</span>
                            <span class="block truncate text-xs text-slate-500">{{ m.email }} · {{ m.mysql_login }}</span>
                        </span>
                        <form
                            hx-boost="false" method="post" action="/admin/instituicoes/{{ institution.id }}/membros/{{ m.id }}/remover"
                            x-data="ajaxForm({
                                confirmTitle: 'Remover vínculo',
                                confirmMessage: 'Remover {{ m.name|e('js') }} de {{ institution.name|e('js') }}? A conta continua ativa; o vínculo fica em Dados excluídos.',
                                confirmLabel: 'Remover',
                                refresh: true,
                            })" @submit.prevent="submit"
                        >
                            {{ csrf_field() }}
                            <button :disabled="loading" type="submit" class="btn-secondary !px-3 !py-1.5 text-xs">Remover</button>
                        </form>
                    </li>
                {% endfor %}
            </ul>
        {% endif %}
    </div>
{% endmacro %}

{% block content %}
    {% import _self as m %}

    <div class="mb-8">
        <a href="/admin/instituicoes" class="text-sm text-slate-500 hover:text-slate-700">← Instituições</a>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">{{ institution.name }}</h1>
        <p class="mt-1 text-sm text-slate-500">Criada em {{ institution.createdAt|date('d/m/Y') }}.</p>
    </div>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        <form
            hx-boost="false" method="post" action="/admin/instituicoes/{{ institution.id }}"
            x-data="ajaxForm({ refresh: true })" @submit.prevent="submit" class="card space-y-3"
        >
            {{ csrf_field() }}
            <h2 class="text-base font-semibold text-slate-900">Nome</h2>
            <input type="text" name="name" value="{{ institution.name }}" required minlength="2" maxlength="120" class="field-input">
            <button :disabled="loading" type="submit" class="btn-secondary">Salvar nome</button>
        </form>

        <div class="card space-y-3">
            <h2 class="text-base font-semibold text-slate-900">Código de convite</h2>
            <p class="text-sm text-slate-500">O aluno digita no cadastro e já entra nesta instituição.</p>
            <p class="font-mono text-2xl tracking-widest {{ institution.inviteCode ? 'text-indigo-700' : 'text-slate-400' }}">{{ institution.inviteCode ?: 'desativado' }}</p>
            <div class="flex flex-wrap gap-2">
                <form
                    hx-boost="false" method="post" action="/admin/instituicoes/{{ institution.id }}/codigo"
                    x-data="ajaxForm({ confirmTitle: 'Gerar novo código', confirmMessage: 'O código atual deixa de valer. Continuar?', confirmLabel: 'Gerar', danger: false, refresh: true })"
                    @submit.prevent="submit"
                >
                    {{ csrf_field() }}
                    <input type="hidden" name="acao" value="gerar">
                    <button :disabled="loading" type="submit" class="btn-secondary !px-3 !py-1.5 text-xs">{{ institution.inviteCode ? 'Gerar novo código' : 'Ativar com novo código' }}</button>
                </form>
                {% if institution.inviteCode %}
                    <form
                        hx-boost="false" method="post" action="/admin/instituicoes/{{ institution.id }}/codigo"
                        x-data="ajaxForm({ confirmTitle: 'Desativar código', confirmMessage: 'Ninguém mais vai conseguir entrar nesta instituição pelo cadastro. Continuar?', confirmLabel: 'Desativar', refresh: true })"
                        @submit.prevent="submit"
                    >
                        {{ csrf_field() }}
                        <input type="hidden" name="acao" value="desativar">
                        <button :disabled="loading" type="submit" class="btn-secondary !px-3 !py-1.5 text-xs">Desativar</button>
                    </form>
                {% endif %}
            </div>
        </div>
    </div>

    <form
        hx-boost="false" method="post" action="/admin/instituicoes/{{ institution.id }}/membros"
        x-data="ajaxForm({ refresh: true })" @submit.prevent="submit"
        class="card mb-6 grid gap-3 sm:grid-cols-[1fr_auto]"
    >
        {{ csrf_field() }}
        <input type="text" name="identificador" required placeholder="Adicionar professor ou aluno: e-mail ou username" class="field-input">
        <button :disabled="loading" type="submit" class="btn-primary">Vincular</button>
    </form>

    <div class="mb-6 grid gap-6 lg:grid-cols-2">
        {{ m.members(professors, institution, 'Professores', 'Nenhum professor vinculado.') }}
        {{ m.members(students, institution, 'Alunos', 'Nenhum aluno vinculado. Compartilhe o código de convite.') }}
    </div>

    <form
        hx-boost="false" method="post" action="/admin/instituicoes/{{ institution.id }}/excluir"
        x-data="ajaxForm({
            confirmTitle: 'Excluir instituição',
            confirmMessage: 'Excluir {{ institution.name|e('js') }}? Os vínculos vão junto para Dados excluídos; as contas continuam ativas.',
            confirmLabel: 'Excluir',
            onSuccess: () => window.navigate('/admin/instituicoes'),
        })" @submit.prevent="submit"
    >
        {{ csrf_field() }}
        <button :disabled="loading" type="submit" class="btn-danger">Excluir instituição</button>
    </form>
{% endblock %}
```

- [ ] **Step 3: Rotas, menu e atalho**

`public/index.php`: `use` das 8 actions (`App\Actions\Institution\...`) em ordem alfabética e, junto das rotas `/admin/*`:

```php
$router->get('/admin/instituicoes', IndexInstitutionsAction::class);
$router->post('/admin/instituicoes', StoreInstitutionAction::class);
$router->get('/admin/instituicoes/{id}', ShowInstitutionAction::class);
$router->post('/admin/instituicoes/{id}', UpdateInstitutionAction::class);
$router->post('/admin/instituicoes/{id}/codigo', InstitutionCodeAction::class);
$router->post('/admin/instituicoes/{id}/membros', AddInstitutionMemberAction::class);
$router->post('/admin/instituicoes/{id}/membros/{member}/remover', RemoveInstitutionMemberAction::class);
$router->post('/admin/instituicoes/{id}/excluir', DestroyInstitutionAction::class);
```

`app/Views/partials/nav-links.twig`: nos dois menus admin, logo depois de "Usuários", `<a href="/admin/instituicoes" ...>Instituições</a>` (mesma classe dos vizinhos).

`app/Views/admin/index.twig`: card `{ href: '/admin/instituicoes', title: 'Instituições', text: 'Escolas e faculdades: professores, alunos e código de convite.' },` logo depois do card de usuários.

- [ ] **Step 4: Verificar**

Run: `vendor/bin/pest && vendor/bin/php-cs-fixer fix --dry-run && docker compose up -d --build app`
Expected: verde; app healthy. No navegador (admin): criar "Escola Teste", ver o código, gerar novo, vincular `professor@dblab.local` e `aluno@dblab.local`, remover o aluno (vai pra Dados excluídos), sem reload em nenhum passo.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Institution app/Views/admin/institutions.twig app/Views/admin/institution.twig public/index.php app/Views/partials/nav-links.twig app/Views/admin/index.twig
git commit -m "Telas de instituições no admin: criar, código de convite e vínculos"
```

---

### Task 6: Professor só vê os alunos das instituições dele

**Files:**
- Modify: `app/Actions/Student/StudentAction.php`, `app/Actions/Student/IndexStudentsAction.php`, `app/Models/Entities/StudentSummary.php`, `app/Views/professor/students/index.twig`
- Test: `tests/Integration/InstitutionsTest.php` (caso do escopo)

**Interfaces:**
- Consumes: `InstitutionScope::canSeeStudent` (Task 2), `InstitutionMember::institutionIdsOf/institutionOfStudent/studentInstitutions`, `Institution::all/find` (Task 4).
- Produces: `StudentSummary::$institutionName` (`?string`); `StudentAction::canSee(User $student): bool`; filtro `?instituicao=<id>|sem` em `/professor/alunos`.

- [ ] **Step 1: Teste de integração do escopo (falha)**

Adicionar a `tests/Integration/InstitutionsTest.php`:

```php
it('lists for a professor only the students of their institutions', function () {
    [$a, $b] = [integrationInstitution(), integrationInstitution()];
    $professor = integrationUser(Role::Professor);
    [$mine, $other, $loose] = [integrationUser(), integrationUser(), integrationUser()];
    InstitutionManager::addMember($a, $professor->email);
    InstitutionManager::addMember($a, $mine->email);
    InstitutionManager::addMember($b, $other->email);

    $visible = App\Actions\Student\IndexStudentsAction::visibleStudentIds(Role::Professor, $professor->id, null);

    expect($visible)->toContain($mine->id)
        ->and($visible)->not->toContain($other->id)
        ->and($visible)->not->toContain($loose->id)
        ->and(App\Actions\Student\IndexStudentsAction::visibleStudentIds(Role::Admin, 0, 'sem'))->toContain($loose->id)
        ->and(App\Actions\Student\IndexStudentsAction::visibleStudentIds(Role::Admin, 0, 'sem'))->not->toContain($mine->id);
});
```

Run: `composer test:integration -- --filter="only the students"`
Expected: FAIL — `Call to undefined method ...visibleStudentIds()`.

- [ ] **Step 2: `StudentAction`**

Em `app/Actions/Student/StudentAction.php`, adicionar `use App\Core\Auth;`, `use App\Models\InstitutionMember;`, `use App\Support\InstitutionScope;` e trocar o corpo de `findStudentOrFail()` por:

```php
        $student = UserModel::find($id);

        // Professor só pode agir sobre contas de aluno, mesmo se souber o id de outra pessoa —
        // e só de alunos de uma instituição em comum (fora disso, "não encontrado": não revela que existe).
        if ($student === null || $student->role !== Role::Aluno || !$this->canSee($student)) {
            $this->respond(false, 'Aluno não encontrado.', '/professor/alunos');
        }

        return $student;
    }

    protected function canSee(User $student): bool
    {
        $viewer = Auth::user();

        return $viewer !== null && InstitutionScope::canSeeStudent(
            $viewer->role,
            InstitutionMember::institutionIdsOf($viewer->id),
            InstitutionMember::institutionOfStudent($student->id),
        );
```

(o `}` final do método já existente fecha `canSee()`.)

- [ ] **Step 3: `StudentSummary`**

Em `app/Models/Entities/StudentSummary.php`: acrescentar `public ?string $institutionName = null,` como último parâmetro do construtor, e trocar `fromUser()` por:

```php
    public static function fromUser(User $user, int $schemasCount, ?string $institutionName = null): self
    {
        return new self($user->id, $user->name, $user->email, $user->active, $user->createdAt, $schemasCount, $institutionName);
    }
```

- [ ] **Step 4: `IndexStudentsAction`**

Substituir o arquivo `app/Actions/Student/IndexStudentsAction.php` por:

```php
<?php

declare(strict_types=1);

namespace App\Actions\Student;

use App\Core\Auth;
use App\Models\Entities\StudentSummary;
use App\Models\Entities\User;
use App\Models\Institution;
use App\Models\InstitutionMember;
use App\Models\SchemaRecord;
use App\Models\User as UserModel;
use App\Support\InstitutionScope;
use App\Support\Role;

/**
 * Gestão de contas de alunos. GET /professor/alunos[?instituicao=<id>|sem]. Professor vê só os
 * alunos das instituições dele; admin vê todos (e pode filtrar os "sem instituição").
 */
final class IndexStudentsAction extends StudentAction
{
    public function __invoke(array $params = []): void
    {
        Auth::requireProfessorOrAdmin();
        $viewer = Auth::user();
        $filter = (string) ($_GET['instituicao'] ?? '');

        $byStudent = InstitutionMember::studentInstitutions();
        $visible = array_flip(self::visibleStudentIds($viewer->role, $viewer->id, $filter));

        $students = [];
        foreach (UserModel::all(Role::Aluno) as $student) {
            if (isset($visible[$student->id])) {
                $students[] = StudentSummary::fromUser($student, count(SchemaRecord::allForUser($student->id)), $byStudent[$student->id]['name'] ?? null);
            }
        }

        $myIds = $viewer->role === Role::Admin ? null : InstitutionMember::institutionIdsOf($viewer->id);
        $options = array_values(array_filter(Institution::all(), static fn ($i): bool => $myIds === null || in_array($i->id, $myIds, true)));

        $this->render('professor/students/index', [
            'pageTitle' => 'Gerenciar alunos',
            'students' => $students,
            'institutions' => $options,
            'filter' => $filter,
            'isAdmin' => $viewer->role === Role::Admin,
        ]);
    }

    /**
     * Ids dos alunos que essa pessoa enxerga, já com o filtro aplicado ('' = todos os visíveis,
     * 'sem' = sem instituição (só admin), '<id>' = de uma instituição).
     *
     * @return list<int>
     */
    public static function visibleStudentIds(Role $role, int $viewerId, ?string $filter): array
    {
        $byStudent = InstitutionMember::studentInstitutions();
        $mine = $role === Role::Admin ? [] : InstitutionMember::institutionIdsOf($viewerId);

        $ids = [];
        foreach (UserModel::all(Role::Aluno) as $student) {
            $institutionId = $byStudent[$student->id]['id'] ?? null;
            if (!InstitutionScope::canSeeStudent($role, $mine, $institutionId)) {
                continue;
            }
            if ($filter === 'sem' && $institutionId !== null) {
                continue;
            }
            if ($filter !== null && $filter !== '' && $filter !== 'sem' && $institutionId !== (int) $filter) {
                continue;
            }
            $ids[] = $student->id;
        }

        return $ids;
    }
}
```

- [ ] **Step 5: View**

Em `app/Views/professor/students/index.twig`, logo depois do `<div class="mb-8 ...">...</div>` do cabeçalho, inserir o filtro:

```twig
    {% if institutions is not empty or isAdmin %}
        <form method="get" action="/professor/alunos" class="mb-4 flex flex-wrap items-center gap-2">
            <select name="instituicao" class="field-input !w-auto" x-data @change="$el.form.requestSubmit()">
                <option value="">{{ isAdmin ? 'Todas as instituições' : 'Todas as minhas instituições' }}</option>
                {% for i in institutions %}
                    <option value="{{ i.id }}" {{ filter == i.id ~ '' ? 'selected' }}>{{ i.name }}</option>
                {% endfor %}
                {% if isAdmin %}<option value="sem" {{ filter == 'sem' ? 'selected' }}>Sem instituição</option>{% endif %}
            </select>
            <noscript><button type="submit" class="btn-secondary">Filtrar</button></noscript>
        </form>
    {% elseif not isAdmin %}
        <p class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Você ainda não está em nenhuma instituição — peça a um admin para vincular você.</p>
    {% endif %}
```

> Alpine (`@change`) em vez de `onchange="..."`: a CSP não tem `'unsafe-inline'` em `script-src`, então handler inline seria bloqueado. `requestSubmit()` passa pelo hx-boost (sem reload).

Na tabela: acrescentar `<th class="py-2 pr-4 font-medium">Instituição</th>` depois da coluna "E-mail" e, na linha, `<td class="py-3 pr-4 text-slate-500">{{ student.institutionName ?? '—' }}</td>` depois do e-mail. Trocar a mensagem de lista vazia por `{{ isAdmin ? 'Nenhum aluno cadastrado ainda.' : 'Nenhum aluno nas suas instituições.' }}`.

- [ ] **Step 6: Rodar**

Run: `composer test:integration && vendor/bin/pest && vendor/bin/php-cs-fixer fix --dry-run`
Expected: verde.

- [ ] **Step 7: Commit**

```bash
git add app/Actions/Student app/Models/Entities/StudentSummary.php app/Views/professor/students/index.twig tests/Integration/InstitutionsTest.php
git commit -m "Professor só vê e gerencia alunos das instituições dele"
```

---

### Task 7: Código de convite no cadastro e troca de papel

**Files:**
- Modify: `app/Actions/Auth/RegisterAction.php`, `app/Views/auth/register.twig`, `app/Actions/Admin/UpdateAdminUserRoleAction.php`

**Interfaces:**
- Consumes: `InviteCode::normalize`, `Institution::findByInviteCode`, `InstitutionMember::add`, `InstitutionManager::syncRoleChange`, `InstitutionException`.

- [ ] **Step 1: Cadastro**

Em `app/Actions/Auth/RegisterAction.php`: adicionar `use App\Models\Institution;`, `use App\Models\InstitutionMember;`, `use App\Support\InviteCode;`. Depois de `$passwordConfirm = ...`, ler o código e incluí-lo em `$old`:

```php
        $inviteInput = trim((string) ($_POST['codigo_instituicao'] ?? ''));
        $old = compact('name', 'email', 'username') + ['codigo_instituicao' => $inviteInput];
```

(trocando o `$old = compact(...)` existente). Logo depois de `$errors = RegistrationValidator::validate(...);`, acrescentar:

```php
            $institution = null;
            if (!$errors && $inviteInput !== '') {
                $code = InviteCode::normalize($inviteInput);
                $institution = $code !== null ? Institution::findByInviteCode($code) : null;
                if ($institution === null) {
                    $errors[] = 'Código da instituição inválido. Confira com seu professor ou deixe o campo em branco.';
                }
            }
```

E trocar o bloco que cria a conta por:

```php
                    $user = UserManager::provisionNewUser($name, $email, $username, $password, Role::registrable());
                    if ($institution !== null) {
                        InstitutionMember::add($institution->id, $user->id, Role::Aluno->value);
                    }

                    AuditLog::record('auth.registered', 'user', $username, ['email' => $email, 'instituicao' => $institution?->name], ['id' => null, 'name' => $name]);
```

- [ ] **Step 2: Formulário**

Em `app/Views/auth/register.twig`, antes do campo de senha, acrescentar:

```twig
        <div>
            <label for="codigo_instituicao" class="field-label">Código da instituição <span class="font-normal text-slate-400">(opcional)</span></label>
            <input type="text" id="codigo_instituicao" name="codigo_instituicao" value="{{ old.codigo_instituicao }}" maxlength="12" autocomplete="off" placeholder="ABCD-EF23" class="field-input font-mono uppercase">
            <p class="mt-1 text-xs text-slate-500">Seu professor passa esse código. Sem ele, um admin vincula você depois.</p>
        </div>
```

- [ ] **Step 3: Troca de papel**

Em `app/Actions/Admin/UpdateAdminUserRoleAction.php`: adicionar `use App\Services\InstitutionException;`, `use App\Services\InstitutionManager;` e, antes de `UserModel::updateRole(...)`:

```php
        try {
            InstitutionManager::syncRoleChange($target, $role);
        } catch (InstitutionException $e) {
            $this->respond(false, $e->getMessage(), '/admin/usuarios');
        }
```

- [ ] **Step 4: Verificar (HTTP local)**

Com o app reconstruído (`docker compose up -d --build app`), criar uma instituição no admin, copiar o código e:
- `POST /register` com o código em minúsculas → conta criada e aparece em `/admin/instituicoes/{id}` como aluno;
- `POST /register` com `codigo_instituicao=ZZZZ-ZZZZ` → página volta com "Código da instituição inválido";
- no `/admin/usuarios`, trocar para "aluno" um professor com 2 vínculos → toast de erro com "Remova os vínculos extras".

Run: `vendor/bin/pest && composer test:integration && vendor/bin/php-cs-fixer fix --dry-run`
Expected: verde.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Auth/RegisterAction.php app/Views/auth/register.twig app/Actions/Admin/UpdateAdminUserRoleAction.php
git commit -m "Cadastro com código de convite; troca de papel respeita os vínculos"
```

---

### Task 8: Verificação no navegador e documentação

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Navegador**

Admin: criar instituição, vincular `professor@dblab.local` e `aluno@dblab.local`; excluir a instituição e restaurar em `/admin/excluidos` (vínculos voltam). Professor (`professor@dblab.local`): `/professor/alunos` mostra só o aluno da instituição; abrir `/professor/alunos/<id de um aluno de fora>/editar` → "Aluno não encontrado". Tudo sem reload.

- [ ] **Step 2: README**

Na tabela de papéis, trocar a linha do professor para dizer que ele gerencia os alunos **das instituições dele**; na tabela do admin, adicionar:

```markdown
| `/admin/instituicoes` | **Instituições.** O admin cria escolas/faculdades e vincula professores (podem estar em várias) e alunos (no máximo uma — garantido por um UNIQUE no MySQL). Professor só vê e gerencia os alunos das instituições dele. Cada instituição tem um código de convite (`XXXX-XXXX`) que o aluno digita no cadastro para já entrar nela; o admin gera outro ou desativa. Excluir instituição ou remover vínculo vai para Dados excluídos. |
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "Documenta instituições"
```

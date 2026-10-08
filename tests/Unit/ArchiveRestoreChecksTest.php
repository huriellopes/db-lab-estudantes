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
        ->and($sqls)->not->toContain('SELECT 1 FROM institution_members WHERE student_user_id = ?');

    // A instituição vem no mesmo lote: não se exige que ela "exista" antes (só o id livre, genérico).
    $institutionMustExist = array_filter($withInstitution, static fn (array $c): bool => $c['expect'] === 'present' && $c['sql'] === 'SELECT 1 FROM institutions WHERE id = ?');
    expect($institutionMustExist)->toBe([]);
});

it('checks a class name in its institution and that members still belong to it', function () {
    $alone = ArchiveRestoreChecks::for([
        archivedItem('class_member', 8, ['class_id' => 4, 'user_id' => 9, 'role' => 'aluno']),
    ]);
    $sqls = array_column($alone, 'sql');
    expect($sqls)->toContain('SELECT 1 FROM classes WHERE id = ?')
        ->and($sqls)->toContain('SELECT 1 FROM institution_members m JOIN classes c ON c.institution_id = m.institution_id WHERE c.id = ? AND m.user_id = ?');

    $classBatch = ArchiveRestoreChecks::for([
        archivedItem('class', 4, ['institution_id' => 2, 'name' => 'BD I']),
        archivedItem('class_member', 8, ['class_id' => 4, 'user_id' => 9, 'role' => 'aluno']),
    ]);
    $sqls = array_column($classBatch, 'sql');
    expect($sqls)->toContain('SELECT 1 FROM classes WHERE institution_id = ? AND name = ?')
        ->and($sqls)->toContain('SELECT 1 FROM institution_members WHERE institution_id = ? AND user_id = ?');
    // A turma vem no mesmo lote: não se exige que ela "exista" antes (só o id livre, genérico).
    $classMustExist = array_filter($classBatch, static fn (array $c): bool => $c['expect'] === 'present' && $c['sql'] === 'SELECT 1 FROM classes WHERE id = ?');
    expect($classMustExist)->toBe([]);

    $institutionBatch = ArchiveRestoreChecks::for([
        archivedItem('institution', 2, ['name' => 'Escola', 'invite_code' => null]),
        archivedItem('class', 4, ['institution_id' => 2, 'name' => 'BD I']),
        archivedItem('class_member', 8, ['class_id' => 4, 'user_id' => 9, 'role' => 'aluno']),
    ]);
    expect(array_column($institutionBatch, 'sql'))->not->toContain('SELECT 1 FROM institution_members WHERE institution_id = ? AND user_id = ?');
});

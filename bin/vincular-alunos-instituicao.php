#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Vincula a uma instituição todos os alunos que ainda não estão em nenhuma.
 *
 *   php bin/vincular-alunos-instituicao.php "ETB"            # simula: mostra o que faria, não muda nada
 *   php bin/vincular-alunos-instituicao.php "ETB" --aplicar  # vincula de verdade
 *
 * Em produção (container da app):
 *   docker exec dblab-app php bin/vincular-alunos-instituicao.php "ETB" [--aplicar]
 *
 * Para cada aluno: já está nesta instituição → nada; está em OUTRA → nada (aluno fica em uma
 * instituição só — remova de lá pelo /admin antes, se for o caso); sem instituição → vincula.
 * Cada vínculo criado vai para a auditoria (institution.member_added, via script). Pode rodar
 * de novo quantas vezes quiser: quem já foi vinculado só aparece como "já estava".
 */

$root = is_file(__DIR__ . '/../vendor/autoload.php') ? dirname(__DIR__) : getcwd();
require $root . '/vendor/autoload.php';

use App\Core\Database;
use App\Models\AuditLog;
use App\Models\InstitutionMember;

$args = array_slice($argv, 1);
$apply = in_array('--aplicar', $args, true);
$name = trim((string) (array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')))[0] ?? ''));

$pdo = Database::connection();
$out = static fn (string $line) => fwrite(STDOUT, $line . PHP_EOL);

if ($name === '') {
    fwrite(STDERR, 'Uso: php bin/vincular-alunos-instituicao.php "<nome da instituição>" [--aplicar]' . PHP_EOL);
    exit(1);
}

$stmt = $pdo->prepare('SELECT id, name FROM institutions WHERE name = ?');
$stmt->execute([$name]);
$institution = $stmt->fetch(PDO::FETCH_ASSOC);
if ($institution === false) {
    $existing = $pdo->query('SELECT name FROM institutions ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    fwrite(STDERR, "Instituição \"{$name}\" não encontrada. Existentes: " . ($existing === [] ? '(nenhuma)' : implode(', ', $existing)) . PHP_EOL);
    exit(1);
}
$institutionId = (int) $institution['id'];

$students = $pdo->query(
    "SELECT u.id, u.name, u.email, m.institution_id, i.name AS institution_name
     FROM users u
     LEFT JOIN institution_members m ON m.user_id = u.id AND m.role = 'aluno'
     LEFT JOIN institutions i ON i.id = m.institution_id
     WHERE u.role = 'aluno'
     ORDER BY u.name",
)->fetchAll(PDO::FETCH_ASSOC);

$already = $elsewhere = $toLink = [];
foreach ($students as $s) {
    if ($s['institution_id'] === null) {
        $toLink[] = $s;
    } elseif ((int) $s['institution_id'] === $institutionId) {
        $already[] = $s;
    } else {
        $elsewhere[] = $s;
    }
}

$out(($apply ? 'APLICANDO' : 'SIMULAÇÃO (nada será alterado; use --aplicar para vincular)') . " — instituição \"{$institution['name']}\" (#{$institutionId})");
$out(sprintf('Alunos: %d | já estavam nesta: %d | em outra instituição: %d | sem instituição (a vincular): %d', count($students), count($already), count($elsewhere), count($toLink)));

if ($elsewhere !== []) {
    $out('');
    $out('Em OUTRA instituição (não mexi — aluno fica em uma só):');
    foreach ($elsewhere as $s) {
        $out("  - {$s['name']} <{$s['email']}> → {$s['institution_name']}");
    }
}

$linked = $failed = 0;
if ($toLink !== []) {
    $out('');
    $out($apply ? 'Vinculando:' : 'Seriam vinculados:');
}
foreach ($toLink as $s) {
    if (!$apply) {
        $out("  - {$s['name']} <{$s['email']}>");
        continue;
    }
    try {
        InstitutionMember::add($institutionId, (int) $s['id'], 'aluno');
        AuditLog::record(
            'institution.member_added',
            'institution',
            $institutionId,
            ['usuario' => $s['email'], 'papel' => 'aluno', 'instituicao' => $institution['name'], 'via' => 'script de vínculo em lote'],
            ['id' => null, 'name' => 'script de vínculo em lote'],
        );
        $out("  ✓ {$s['name']} <{$s['email']}>");
        $linked++;
    } catch (Throwable $e) {
        // Ex.: o aluno entrou em outra instituição entre a listagem e aqui (o UNIQUE barra).
        $out("  ✗ {$s['name']} <{$s['email']}>: " . $e->getMessage());
        $failed++;
    }
}

if ($apply && $linked > 0 && class_exists(\App\Services\ProfessorGrants::class)) {
    \App\Services\ProfessorGrants::syncAll();
    $out('Permissões dos professores sincronizadas.');
}

if ($apply) {
    $out('');
    $out("Resultado: {$linked} vinculado(s), {$failed} falha(s), " . count($already) . ' já estavam, ' . count($elsewhere) . ' em outra instituição.');
}

exit($failed > 0 ? 2 : 0);

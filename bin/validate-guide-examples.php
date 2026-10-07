#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Roda todos os exemplos de código do guia (app/Views/guide/*.twig) no banco de verdade e
 * confere o "Resultado" mostrado logo abaixo de cada um — pra nenhum aluno copiar um exemplo
 * quebrado ou com resultado inventado.
 *
 * Cada exemplo é um embed de partials/guide/code.twig com `engine: '...'`; o resultado
 * esperado é o embed de partials/guide/output.twig que vier logo depois. Os exemplos de uma
 * página rodam em ordem, na mesma sessão/banco (um pode depender da tabela criada no anterior),
 * num container descartável por engine — nunca no MySQL do laboratório.
 *
 * Parâmetros extras aceitos no embed do código:
 *   skip: true          não roda (string de conexão, pseudo-código, comando de shell...)
 *   expect_error: true  o exemplo PRECISA falhar (ex.: mostrar um erro comum de propósito)
 * E no embed do resultado:
 *   compare: false      só garante que o código rodou, sem comparar o texto da saída
 *
 * Uso:
 *   php bin/validate-guide-examples.php                 # todas as páginas
 *   php bin/validate-guide-examples.php mysql sql-ansi  # só essas páginas
 *   php bin/validate-guide-examples.php --print sql-ansi  # mostra a saída real de cada exemplo
 *   php bin/validate-guide-examples.php --fill sql-ansi   # preenche os resultados marcados como
 *                                                         # PENDENTE com a saída real (pra escrever
 *                                                         # exemplo novo sem digitar resultado à mão)
 *   php bin/validate-guide-examples.php --refill sql-ansi # regera TODOS os resultados comparáveis
 *                                                         # (depois de mudar um exemplo de propósito)
 *   php bin/validate-guide-examples.php --stop          # derruba os containers de validação
 *
 * Precisa de Docker. Primeira execução baixa as imagens (Oracle/SQL Server são grandes).
 */

const GUIDE_DIR = __DIR__ . '/../app/Views/guide';

const CONTAINERS = [
    'mysql' => ['name' => 'guia-validacao-mysql', 'image' => 'mysql:8.0.46', 'env' => ['MYSQL_ROOT_PASSWORD=guia']],
    'postgres' => ['name' => 'guia-validacao-postgres', 'image' => 'postgres:18', 'env' => ['POSTGRES_PASSWORD=guia']],
    'mssql' => ['name' => 'guia-validacao-mssql', 'image' => 'mcr.microsoft.com/mssql/server:2025-latest', 'env' => ['ACCEPT_EULA=Y', 'MSSQL_SA_PASSWORD=Guia_Validacao_123']],
    'oracle' => ['name' => 'guia-validacao-oracle', 'image' => 'gvenzl/oracle-free:slim', 'env' => ['ORACLE_PASSWORD=guia']],
    'mongo' => ['name' => 'guia-validacao-mongo', 'image' => 'mongo:8', 'env' => []],
    'redis' => ['name' => 'guia-validacao-redis', 'image' => 'redis:8', 'env' => []],
];

$args = array_slice($argv, 1);
$print = in_array('--print', $args, true);
$refill = in_array('--refill', $args, true);
$fill = $refill || in_array('--fill', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => !in_array($a, ['--print', '--fill', '--refill'], true)));

if (in_array('--stop', $args, true)) {
    foreach (CONTAINERS as $c) {
        shell_exec('docker rm -f ' . escapeshellarg($c['name']) . ' 2>/dev/null');
    }
    echo "Containers de validação removidos.\n";
    exit(0);
}

$pages = $args !== [] ? $args : array_map(
    static fn (string $f): string => basename($f, '.twig'),
    array_filter(glob(GUIDE_DIR . '/*.twig') ?: [], static fn (string $f): bool => basename($f) !== 'index.twig'),
);

$failures = 0;
$total = 0;

foreach ($pages as $page) {
    $file = GUIDE_DIR . "/{$page}.twig";
    if (!is_file($file)) {
        fwrite(STDERR, "Página não encontrada: {$page}\n");
        exit(2);
    }

    $blocks = parseBlocks((string) file_get_contents($file));
    if ($blocks === []) {
        continue;
    }

    echo "== {$page} (" . count(array_filter($blocks, static fn (array $b): bool => $b['type'] === 'code')) . " exemplos)\n";
    $reset = [];
    $fills = [];

    foreach ($blocks as $i => $block) {
        if ($block['type'] !== 'code' || $block['skip'] || $block['engine'] === 'text') {
            continue;
        }
        $engine = $block['engine'];
        if (!isset(CONTAINERS[$engine])) {
            fwrite(STDERR, "  engine desconhecida: {$engine}\n");
            $failures++;
            continue;
        }
        if (!isset($reset[$engine])) {
            ensureContainer($engine);
            resetEngine($engine);
            $reset[$engine] = true;
        }

        $total++;
        [$ok, $out] = runCode($engine, $block['code']);
        $label = sprintf('  #%02d %-8s linha %-4d', $i, $engine, $block['line']);

        if ($block['expectError']) {
            if ($ok) {
                $failures++;
                echo "{$label} FALHOU — devia dar erro e rodou sem erro\n";
            } else {
                echo "{$label} ok (erro esperado)\n";
            }
            if ($print) {
                echo indent($out);
            }
            continue;
        }

        if (!$ok) {
            $failures++;
            echo "{$label} FALHOU — erro ao executar:\n" . indent($out);
            continue;
        }

        $next = $blocks[$i + 1] ?? null;
        $pending = $next !== null && $next['type'] === 'output'
            && (trim($next['text']) === 'PENDENTE' || ($refill && $next['compare']));
        if ($pending) {
            if ($fill) {
                $fills[$next['offset']] = [$next['length'], $out];
                echo "{$label} ok (resultado preenchido)\n";
            } else {
                $failures++;
                echo "{$label} FALHOU — resultado PENDENTE (rode com --fill)\n";
            }
            continue;
        }
        if ($next !== null && $next['type'] === 'output' && $next['compare']) {
            if (normalize($out) !== normalize($next['text'])) {
                $failures++;
                echo "{$label} FALHOU — resultado diferente do mostrado na página\n";
                echo "    esperado:\n" . indent($next['text'], 6) . "    obtido:\n" . indent($out, 6);
                continue;
            }
            echo "{$label} ok (resultado conferido)\n";
        } else {
            echo "{$label} ok\n";
        }
        if ($print) {
            echo indent($out);
        }
    }

    if ($fills !== []) {
        // De trás pra frente, pra os offsets dos blocos anteriores continuarem valendo.
        krsort($fills);
        $twig = (string) file_get_contents($file);
        foreach ($fills as $offset => [$length, $out]) {
            $twig = substr_replace($twig, htmlspecialchars($out, ENT_NOQUOTES | ENT_HTML5), $offset, $length);
        }
        file_put_contents($file, $twig);
        echo "  " . count($fills) . " resultado(s) preenchido(s) em {$page}.twig\n";
    }
}

echo "\n{$total} exemplos executados, {$failures} falha(s).\n";
exit($failures > 0 ? 1 : 0);

/**
 * @return list<array{type: string, engine?: string, code?: string, text?: string, skip?: bool, expectError?: bool, compare?: bool, line: int}>
 */
function parseBlocks(string $twig): array
{
    $pattern = "/\\{% embed 'partials\\/guide\\/(code|output)\\.twig'(?: with (\\{.*?\\}))? %\\}\\{% block (?:code|out) -%\\}\\n(.*?)\\n\\{%- endblock %\\}\\{% endembed %\\}/s";
    preg_match_all($pattern, $twig, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

    $blocks = [];
    foreach ($matches as $m) {
        $params = $m[2][0] ?? '';
        $content = html_entity_decode($m[3][0], ENT_QUOTES | ENT_HTML5);
        $line = substr_count(substr($twig, 0, $m[0][1]), "\n") + 1;
        if ($m[1][0] === 'code') {
            preg_match("/engine: '([a-z]+)'/", $params, $engine);
            $blocks[] = [
                'type' => 'code',
                'engine' => $engine[1] ?? 'text',
                'code' => $content,
                'skip' => str_contains($params, 'skip: true'),
                'expectError' => str_contains($params, 'expect_error: true'),
                'line' => $line,
            ];
        } else {
            $blocks[] = [
                'type' => 'output',
                'text' => $content,
                'compare' => !str_contains($params, 'compare: false'),
                'line' => $line,
                // Posição do texto do resultado no arquivo, pra o --fill/--refill reescrever no lugar.
                'offset' => $m[3][1],
                'length' => strlen($m[3][0]),
            ];
        }
    }

    return $blocks;
}

function ensureContainer(string $engine): void
{
    $c = CONTAINERS[$engine];
    $running = trim((string) shell_exec('docker inspect -f {{.State.Running}} ' . escapeshellarg($c['name']) . ' 2>/dev/null'));
    if ($running !== 'true') {
        shell_exec('docker rm -f ' . escapeshellarg($c['name']) . ' 2>/dev/null');
        $env = implode(' ', array_map(static fn (string $e): string => '-e ' . escapeshellarg($e), $c['env']));
        echo "  subindo container {$c['name']} ({$c['image']})...\n";
        shell_exec("docker run -d --name {$c['name']} {$env} {$c['image']} >/dev/null");
    }

    $probe = [
        'mysql' => 'mysql -uroot -pguia -e "SELECT 1"',
        'postgres' => 'psql -U postgres -c "SELECT 1"',
        'mssql' => "/opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P 'Guia_Validacao_123' -Q 'SELECT 1'",
        'oracle' => 'healthcheck.sh',
        'mongo' => 'mongosh --quiet --eval "db.runCommand({ping: 1}).ok"',
        'redis' => 'redis-cli ping',
    ][$engine];

    for ($i = 0; $i < 120; $i++) {
        exec('docker exec ' . escapeshellarg($c['name']) . ' sh -c ' . escapeshellarg($probe) . ' >/dev/null 2>&1', $_, $code);
        if ($code === 0) {
            return;
        }
        sleep(3);
    }
    fwrite(STDERR, "Container {$c['name']} não ficou pronto a tempo.\n");
    exit(2);
}

/** Banco limpo no começo de cada página — os exemplos de uma página nunca dependem de outra. */
function resetEngine(string $engine): void
{
    $setup = [
        'mysql' => 'DROP DATABASE IF EXISTS guia; CREATE DATABASE guia; ',
        'postgres' => 'DROP DATABASE IF EXISTS guia; CREATE DATABASE guia;',
        'mssql' => "IF DB_ID('guia') IS NOT NULL BEGIN ALTER DATABASE guia SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE guia; END; CREATE DATABASE guia;",
        'oracle' => null,
        'mongo' => 'db.getSiblingDB("guia").dropDatabase()',
        'redis' => 'FLUSHALL',
    ][$engine];

    if ($engine === 'oracle') {
        // Um usuário (= schema) novo por página.
        runCode('oracle', "BEGIN EXECUTE IMMEDIATE 'DROP USER guia CASCADE'; EXCEPTION WHEN OTHERS THEN NULL; END;\n/\nCREATE USER guia IDENTIFIED BY guia QUOTA UNLIMITED ON users;\nGRANT CONNECT, RESOURCE, CREATE VIEW TO guia;", asSystem: true);

        return;
    }
    if ($engine === 'postgres') {
        exec('docker exec ' . CONTAINERS['postgres']['name'] . ' psql -U postgres -q -c "DROP DATABASE IF EXISTS guia" -c "CREATE DATABASE guia" 2>&1');

        return;
    }
    runCode($engine, $setup, asSystem: true);
}

/** @return array{0: bool, 1: string} */
function runCode(string $engine, string $code, bool $asSystem = false): array
{
    $name = CONTAINERS[$engine]['name'];
    $cmd = [
        'mysql' => 'mysql -uroot -pguia --table --default-character-set=utf8mb4' . ($asSystem ? '' : ' guia'),
        'postgres' => 'psql -U postgres -X -q -v ON_ERROR_STOP=1 -P footer=on -d guia',
        'mssql' => "/opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P 'Guia_Validacao_123' -b -W -s '|'" . ($asSystem ? '' : ' -d guia'),
        'oracle' => $asSystem ? 'sqlplus -s -L system/guia@localhost/FREEPDB1' : 'sqlplus -s -L guia/guia@localhost/FREEPDB1',
        'mongo' => 'mongosh --quiet guia',
        'redis' => 'redis-cli --no-raw',
    ][$engine];

    if ($engine === 'oracle') {
        $code = "SET FEEDBACK OFF\nSET PAGESIZE 100\nSET LINESIZE 200\nSET TRIMSPOOL ON\nSET SERVEROUTPUT ON\nWHENEVER SQLERROR EXIT FAILURE\n{$code}\nEXIT\n";
    }
    if ($engine === 'mongo') {
        // Cada exemplo é um script; sem isso o mongosh imprime o "eco" de cada linha.
        $code .= "\n";
    }

    // LANG=C.UTF-8: sem isso o cliente mysql (locale POSIX do container) calcula errado a
    // largura de letra acentuada e desalinha as tabelas do resultado.
    $proc = proc_open(
        'docker exec -i -e LANG=C.UTF-8 -e LC_ALL=C.UTF-8 ' . escapeshellarg($name) . ' sh -c ' . escapeshellarg($cmd),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    fwrite($pipes[0], $code . "\n");
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    $exit = proc_close($proc);

    $stderr = implode("\n", array_filter(
        explode("\n", $stderr),
        static fn (string $l): bool => !str_contains($l, 'Using a password on the command line'),
    ));

    $ok = $exit === 0 && !($engine === 'redis' && preg_match('/^\(error\)/m', $stdout));

    return [$ok, trim($stdout . ($stderr !== '' ? "\n" . $stderr : ''))];
}

/** Compara ignorando espaços repetidos e linhas em branco — o que importa é o conteúdo. */
function normalize(string $text): string
{
    $lines = array_map(static fn (string $l): string => preg_replace('/\s+/', ' ', trim($l)) ?? '', explode("\n", trim($text)));

    return implode("\n", array_values(array_filter($lines, static fn (string $l): bool => $l !== '')));
}

function indent(string $text, int $spaces = 4): string
{
    $pad = str_repeat(' ', $spaces);

    return $pad . str_replace("\n", "\n{$pad}", rtrim($text)) . "\n";
}

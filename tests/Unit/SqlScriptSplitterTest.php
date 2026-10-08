<?php

declare(strict_types=1);

use App\Support\SqlScriptSplitter;

it('returns a single statement unchanged when there is no semicolon', function () {
    expect(SqlScriptSplitter::split('SELECT 1'))->toBe(['SELECT 1']);
});

it('splits multiple statements separated by semicolons', function () {
    expect(SqlScriptSplitter::split('SELECT 1; SELECT 2; SELECT 3'))
        ->toBe(['SELECT 1', 'SELECT 2', 'SELECT 3']);
});

it('trims whitespace around each statement', function () {
    expect(SqlScriptSplitter::split("  SELECT 1  ;\n  SELECT 2  "))
        ->toBe(['SELECT 1', 'SELECT 2']);
});

it('ignores a trailing semicolon and empty statements between semicolons', function () {
    expect(SqlScriptSplitter::split('SELECT 1;;; SELECT 2;'))
        ->toBe(['SELECT 1', 'SELECT 2']);
});

it('returns an empty list for blank input', function () {
    expect(SqlScriptSplitter::split(''))->toBe([])
        ->and(SqlScriptSplitter::split('   '))->toBe([])
        ->and(SqlScriptSplitter::split(';;;'))->toBe([]);
});

it('does not split on a semicolon inside a single-quoted string', function () {
    expect(SqlScriptSplitter::split("INSERT INTO t (nota) VALUES ('a; b'); SELECT 1"))
        ->toBe(["INSERT INTO t (nota) VALUES ('a; b')", 'SELECT 1']);
});

it('does not split on a semicolon inside a double-quoted string', function () {
    expect(SqlScriptSplitter::split('INSERT INTO t (nota) VALUES ("a; b"); SELECT 1'))
        ->toBe(['INSERT INTO t (nota) VALUES ("a; b")', 'SELECT 1']);
});

it('does not split on a semicolon inside a backtick identifier', function () {
    expect(SqlScriptSplitter::split('SELECT `weird;column` FROM t; SELECT 1'))
        ->toBe(['SELECT `weird;column` FROM t', 'SELECT 1']);
});

it('respects an escaped quote inside a string literal', function () {
    expect(SqlScriptSplitter::split("SELECT 'it''s; fine'; SELECT 1"))
        ->toBe(["SELECT 'it''s; fine'", 'SELECT 1']);
});

it('respects a backslash-escaped quote inside a string literal', function () {
    expect(SqlScriptSplitter::split("SELECT 'a\\'; still one string'; SELECT 1"))
        ->toBe(["SELECT 'a\\'; still one string'", 'SELECT 1']);
});

it('strips a line comment (--) and keeps splitting normally', function () {
    $script = "SELECT 1; -- isso é um comentário; com ponto e virgula dentro\nSELECT 2;";

    expect(SqlScriptSplitter::split($script))->toBe(['SELECT 1', 'SELECT 2']);
});

it('strips a line comment (#) and keeps splitting normally', function () {
    $script = "SELECT 1; # outro comentário; com ponto e virgula\nSELECT 2;";

    expect(SqlScriptSplitter::split($script))->toBe(['SELECT 1', 'SELECT 2']);
});

it('strips a block comment, including one that spans multiple lines', function () {
    $script = "SELECT 1; /* comentário\ncom várias linhas; e ponto e vírgula */ SELECT 2;";

    expect(SqlScriptSplitter::split($script))->toBe(['SELECT 1', 'SELECT 2']);
});

it('handles a realistic multi-statement script end to end', function () {
    $script = <<<'SQL'
        CREATE TABLE alunos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(100)
        );
        -- popula alguns alunos
        INSERT INTO alunos (nome) VALUES ('Ana; Beatriz'), ('Bruno');
        SELECT * FROM alunos WHERE nome = "Ana; Beatriz";
        SQL;

    $statements = SqlScriptSplitter::split($script);

    expect($statements)->toHaveCount(3)
        ->and($statements[0])->toStartWith('CREATE TABLE alunos')
        ->and($statements[1])->toBe("INSERT INTO alunos (nome) VALUES ('Ana; Beatriz'), ('Bruno')")
        ->and($statements[2])->toBe('SELECT * FROM alunos WHERE nome = "Ana; Beatriz"');
});

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

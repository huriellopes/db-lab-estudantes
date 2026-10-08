<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Separa um script com vários comandos SQL em statements individuais — usado pelo
 * console SQL do dashboard, pra rodar um por vez (PDO não roda multi-statement por
 * padrão, e não queremos ligar isso: um erro de sintaxe travando tudo empilhado é pior
 * pra depurar do que rodar/relatar comando a comando).
 *
 * Não é um parser SQL completo: só o suficiente pra não quebrar um ';' que esteja dentro
 * de uma string ('...'/"..."), de um identificador entre crases (`...`) ou de um
 * comentário (-- até o fim da linha, # até o fim da linha, ou bloco /* ... * /).
 *
 * Entende `DELIMITER <x>` no começo de uma linha, como o cliente `mysql`: é como um script
 * de restauração com trigger/procedure (BEGIN ... ; ... END) chega inteiro no console —
 * ver App\Support\ProgrammableObjects::restoreScript().
 */
final class SqlScriptSplitter
{
    /** @return list<string> Statements não vazios, sem o ';' final, com espaço nas pontas removido. */
    public static function split(string $script): array
    {
        $statements = [];
        $current = '';
        $length = strlen($script);
        $quote = null;
        $i = 0;
        $delimiter = ';';

        while ($i < $length) {
            $char = $script[$i];
            $next = $i + 1 < $length ? $script[$i + 1] : '';

            if ($quote !== null) {
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $current .= $char . $next;
                    $i += 2;
                    continue;
                }
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                $i++;
                continue;
            }

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

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                $i++;
                continue;
            }

            if (($char === '-' && $next === '-') || $char === '#') {
                while ($i < $length && $script[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $i += 2;
                while ($i < $length && !($script[$i] === '*' && ($script[$i + 1] ?? '') === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            if (substr_compare($script, $delimiter, $i, strlen($delimiter)) === 0) {
                $statements[] = trim($current);
                $current = '';
                $i += strlen($delimiter);
                continue;
            }

            $current .= $char;
            $i++;
        }

        $statements[] = trim($current);

        return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
    }
}

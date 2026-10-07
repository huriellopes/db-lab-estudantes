<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOStatement;

/**
 * Resultado do console SQL lido até um teto de linhas (ver App\Actions\SqlConsole\RunSqlAction).
 *
 * Antes era fetchAll() e só DEPOIS o corte — uma SELECT com produto cartesiano carregava
 * milhões de linhas na memória do PHP-FPM pra mostrar só as primeiras 300. Aqui lê uma
 * linha por vez e para no teto (+1, só pra saber se havia mais). Só economiza memória de
 * verdade com a conexão em modo unbuffered (ver Database::connectAs): no modo buffered
 * padrão, o driver já traz o resultado inteiro antes do primeiro fetch().
 */
final readonly class CappedResult
{
    /** @param list<array<string, mixed>> $rows */
    private function __construct(
        public array $rows,
        public bool $truncated,
    ) {
    }

    public static function fetch(PDOStatement $stmt, int $max): self
    {
        $rows = [];
        $truncated = false;

        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (count($rows) === $max) {
                $truncated = true;
                break;
            }
            $rows[] = $row;
        }

        // Em unbuffered, o resto do resultado continua pendente na conexão — sem isso, o
        // próximo comando do mesmo script falharia com "Cannot execute queries while other
        // unbuffered queries are active".
        $stmt->closeCursor();

        return new self($rows, $truncated);
    }
}

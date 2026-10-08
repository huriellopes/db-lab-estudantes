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

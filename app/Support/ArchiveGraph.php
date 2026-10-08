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
    public const MODELS = ['user', 'schema', 'saved_query', 'er_diagram', 'institution', 'institution_member', 'class', 'class_member'];

    private const TABLES = [
        'user' => 'users',
        'schema' => 'schemas_criados',
        'saved_query' => 'saved_queries',
        'er_diagram' => 'er_diagrams',
        'institution' => 'institutions',
        'institution_member' => 'institution_members',
        'class' => 'classes',
        'class_member' => 'class_members',
    ];

    /** Modelo => [modelo filho => coluna FK no filho]. */
    private const CHILDREN = [
        'user' => ['schema' => 'user_id', 'saved_query' => 'user_id', 'er_diagram' => 'user_id', 'institution_member' => 'user_id', 'class_member' => 'user_id'],
        'institution' => ['institution_member' => 'institution_id', 'class' => 'institution_id'],
        'class' => ['class_member' => 'class_id'],
    ];

    private const TYPE_LABELS = [
        'user' => 'Usuário',
        'schema' => 'Schema',
        'saved_query' => 'Consulta salva',
        'er_diagram' => 'Diagrama ER',
        'institution' => 'Instituição',
        'institution_member' => 'Vínculo com instituição',
        'class' => 'Turma',
        'class_member' => 'Vínculo com turma',
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
            'institution', 'class' => (string) ($row['name'] ?? '?'),
            'institution_member' => sprintf('Usuário #%s → instituição #%s (%s)', $row['user_id'] ?? '?', $row['institution_id'] ?? '?', $row['role'] ?? '?'),
            'class_member' => sprintf('Usuário #%s → turma #%s (%s)', $row['user_id'] ?? '?', $row['class_id'] ?? '?', $row['role'] ?? '?'),
            default => throw new InvalidArgumentException("Modelo desconhecido no arquivo: {$model}"),
        };

        return mb_substr($label, 0, self::MAX_LABEL);
    }

    public static function typeLabel(string $model): string
    {
        return self::TYPE_LABELS[$model] ?? $model;
    }
}

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

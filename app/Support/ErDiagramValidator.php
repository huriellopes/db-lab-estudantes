<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Validação pura do título/JSON de um diagrama do laboratório de modelagem (ver
 * App\Actions\ErDiagram\StoreErDiagramAction/UpdateErDiagramAction) — compartilhada entre
 * criar e atualizar, sem depender de I/O.
 */
final class ErDiagramValidator
{
    private const MAX_TITLE_LENGTH = 80;

    // Generoso o bastante pra qualquer diagrama razoável, mas barra um payload absurdo
    // vindo de alguém tentando abusar do endpoint.
    private const MAX_DATA_BYTES = 200_000;

    /**
     * @return array{0: string, 1: string, 2: string|null} [título, data (JSON já normalizado), mensagem de erro ou null]
     */
    public static function validate(string $rawTitle, string $rawData): array
    {
        $title = trim($rawTitle);

        if ($title === '') {
            return ['', '', 'Dê um nome pra esse diagrama antes de salvar.'];
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return ['', '', 'Nome muito longo (máximo de ' . self::MAX_TITLE_LENGTH . ' caracteres).'];
        }
        if (strlen($rawData) > self::MAX_DATA_BYTES) {
            return ['', '', 'Diagrama grande demais pra salvar.'];
        }

        $decoded = json_decode($rawData, associative: true);
        if (!is_array($decoded) || !isset($decoded['entities'], $decoded['relationships'])) {
            return ['', '', 'Diagrama inválido — tente recarregar a página.'];
        }

        // Re-serializa (em vez de gravar $rawData direto) pra nunca guardar JSON malformado
        // no banco, mesmo que o client mande algo levemente diferente do esperado.
        return [$title, json_encode($decoded), null];
    }
}

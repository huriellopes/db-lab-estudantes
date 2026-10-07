<?php

declare(strict_types=1);

namespace App\Support;

final class SchemaNameBuilder
{
    // Sem "_" no início: "<prefixo>__" + "_x" daria "<prefixo>___x", e o primeiro "__" do
    // nome deixaria de marcar sozinho onde o prefixo termina (ver MysqlIdentifier).
    private const LABEL_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_]{0,39}$/';
    private const DB_NAME_PATTERN = '/^[a-z0-9_]{1,64}$/';
    private const MAX_DB_NAME_LENGTH = 64;

    public static function isValidLabel(string $label): bool
    {
        return (bool) preg_match(self::LABEL_PATTERN, $label);
    }

    /**
     * Valida o charset de um db_name recebido do usuário ANTES de interpolá-lo em DDL
     * (CREATE/DROP DATABASE, GRANT). Sem isso, alguém poderia fechar o identificador
     * entre crases (`) e injetar SQL arbitrário via o campo db_name do formulário.
     */
    public static function isValidDbName(string $dbName): bool
    {
        return (bool) preg_match(self::DB_NAME_PATTERN, $dbName);
    }

    /**
     * Nome final do database: <schema_prefix>__<label em minúsculo>. O prefixo é
     * users.schema_prefix — fixado na criação da conta e que NÃO muda quando a pessoa
     * renomeia o login MySQL. Se mudasse, o login antigo ficaria livre pra outra pessoa
     * cadastrar e herdar (via GRANT com wildcard) os databases que continuam com o nome antigo.
     */
    public static function build(string $schemaPrefix, string $label): string
    {
        return $schemaPrefix . '__' . strtolower($label);
    }

    /**
     * Pattern de GRANT em nível de database pra todos os schemas do prefixo. "_" é wildcard
     * de 1 caractere em pattern de GRANT mesmo dentro de crases, então todo "_" literal vira
     * "\_". Sem isso, "ana_costa" também alcançaria "anaXcosta__...".
     */
    public static function grantPattern(string $schemaPrefix): string
    {
        return str_replace('_', '\\_', $schemaPrefix . '__') . '%';
    }

    /** Mesmo recorte de grantPattern(), pra `SHOW DATABASES LIKE` (escapa "\\", "%" e "_"). */
    public static function likePattern(string $schemaPrefix): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $schemaPrefix . '__') . '%';
    }

    public static function isWithinLengthLimit(string $dbName): bool
    {
        return strlen($dbName) <= self::MAX_DB_NAME_LENGTH;
    }

    public static function isOwnedBy(string $dbName, string $schemaPrefix): bool
    {
        return str_starts_with($dbName, $schemaPrefix . '__');
    }
}

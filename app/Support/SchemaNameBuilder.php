<?php

namespace App\Support;

class SchemaNameBuilder
{
    private const LABEL_PATTERN = '/^[A-Za-z0-9_]{1,40}$/';
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

    /** Nome final do database: <mysql_login>__<label em minúsculo>. */
    public static function build(string $mysqlLogin, string $label): string
    {
        return $mysqlLogin . '__' . strtolower($label);
    }

    public static function isWithinLengthLimit(string $dbName): bool
    {
        return strlen($dbName) <= self::MAX_DB_NAME_LENGTH;
    }

    public static function isOwnedBy(string $dbName, string $mysqlLogin): bool
    {
        return str_starts_with($dbName, $mysqlLogin . '__');
    }
}

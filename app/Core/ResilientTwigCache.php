<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\ErrorLogger;
use RuntimeException;
use Twig\Cache\FilesystemCache;

/**
 * Cache de templates compilados que nunca derruba a página. O cache é só otimização: se não
 * der pra gravar (subdiretório sumiu no meio do "Limpar cache do Twig", ou ficou com dono
 * root depois de um `docker compose exec` sem `-u www-data`), o Twig compila em memória e
 * roda o resultado com eval — a página sai igual, só sem o atalho. O FilesystemCache padrão
 * lança RuntimeException nesse caso e o aluno via um 500.
 */
final class ResilientTwigCache extends FilesystemCache
{
    public function write(string $key, string $content): void
    {
        try {
            parent::write($key, $content);
        } catch (RuntimeException $e) {
            ErrorLogger::message('warning', 'Cache do Twig não gravado (página renderizada sem cache): ' . $e->getMessage(), $key);
        }
    }
}

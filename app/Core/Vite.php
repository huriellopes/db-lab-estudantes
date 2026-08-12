<?php

declare(strict_types=1);

namespace App\Core;

use Twig\Markup;

/**
 * Resolve as tags <script>/<link> dos assets buildados pelo Vite.
 *
 * - Em dev (variável de ambiente VITE_DEV_SERVER_URL definida, ex.: http://localhost:5173,
 *   quando se roda `npm run dev`): aponta direto para o servidor de desenvolvimento do Vite.
 * - Em produção (padrão, dentro do container): lê public/build/manifest.json (gerado por
 *   `npm run build`) para descobrir os nomes com hash dos arquivos finais.
 */
final class Vite
{
    /** @var list<string> */
    private const ENTRIES = ['resources/css/app.css', 'resources/js/app.js'];

    public static function tags(): Markup
    {
        $devServerUrl = Config::get('VITE_DEV_SERVER_URL');

        $html = $devServerUrl !== null ? self::devTags($devServerUrl) : self::prodTags();

        return new Markup($html, 'UTF-8');
    }

    private static function devTags(string $devServerUrl): string
    {
        $devServerUrl = rtrim($devServerUrl, '/');
        $tags = [sprintf('<script type="module" src="%s/@vite/client"></script>', $devServerUrl)];

        foreach (self::ENTRIES as $entry) {
            $tags[] = sprintf('<script type="module" src="%s/%s"></script>', $devServerUrl, $entry);
        }

        return implode("\n", $tags);
    }

    private static function prodTags(): string
    {
        // Vite 5+ escreve o manifest dentro de um subdiretório ".vite" por padrão.
        $manifestPath = dirname(__DIR__, 2) . '/public/build/.vite/manifest.json';

        $contents = is_file($manifestPath) ? file_get_contents($manifestPath) : false;

        if ($contents === false) {
            return '<!-- build de assets não encontrado: rode "npm run build" -->';
        }

        /** @var array<string, array{file: string, css?: list<string>}> $manifest */
        $manifest = json_decode($contents, true) ?? [];
        $tags = [];

        foreach (self::ENTRIES as $entry) {
            if (!isset($manifest[$entry])) {
                continue;
            }

            $chunk = $manifest[$entry];
            $file = '/build/' . $chunk['file'];

            $tags[] = str_ends_with($chunk['file'], '.css')
                ? sprintf('<link rel="stylesheet" href="%s">', $file)
                : sprintf('<script type="module" src="%s"></script>', $file);

            foreach ($chunk['css'] ?? [] as $cssFile) {
                $tags[] = sprintf('<link rel="stylesheet" href="/build/%s">', $cssFile);
            }
        }

        return implode("\n", $tags);
    }
}

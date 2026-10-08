<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Core\Action;
use App\Core\Auth;
use App\Services\LogTail;

/**
 * O que entrou numa fonte de log depois do cursor — chamado a cada ~2s pela página de logs
 * ao vivo (Alpine `liveLog`, resources/js/app.js). GET /admin/logs/ao-vivo/feed?fonte=&cursor=.
 */
final class LiveLogsFeedAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireAdmin();
        // Libera o lock do arquivo de sessão já: com polling a cada 2s, segurar a sessão
        // enfileiraria as outras abas/requisições do mesmo admin atrás desta.
        session_write_close();

        $source = (string) ($_GET['fonte'] ?? '');
        if (!in_array($source, LogTail::SOURCES, true)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Fonte de log inválida.']);

            return;
        }

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode(
            ['success' => true, ...LogTail::read($source, mb_substr((string) ($_GET['cursor'] ?? ''), 0, 64))],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }
}

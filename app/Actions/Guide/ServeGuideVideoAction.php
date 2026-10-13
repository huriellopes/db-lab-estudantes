<?php

declare(strict_types=1);

namespace App\Actions\Guide;

use App\Core\Action;
use App\Core\Auth;
use App\Support\GuideVideos;

/**
 * Entrega um arquivo de vídeo-aula do guia (vídeo, pôster ou legenda). GET /guia/video/{file}.
 *
 * O PHP só confere o login e se o arquivo é de um vídeo declarado em App\Support\GuideVideos;
 * quem manda os bytes é o nginx, pelo X-Accel-Redirect pra location interna /_guide-videos/
 * (ver docker/nginx.conf). Assim o guia continua só pra quem está logado, sem prender um
 * worker do PHP-FPM durante o streaming, e com range requests (o "arrastar" do player)
 * resolvidos pelo próprio nginx.
 */
final class ServeGuideVideoAction extends Action
{
    public function __invoke(array $params = []): void
    {
        Auth::requireLogin();

        $file = (string) ($params['file'] ?? '');
        $type = GuideVideos::contentType($file);
        if ($type === null || !is_file(GuideVideos::path($file))) {
            http_response_code(404);
            $this->render('errors/404');

            return;
        }

        // A sessão manda no-store/Pragma/Expires; aqui o arquivo é estático e igual pra todo
        // aluno logado, então o navegador pode guardar (mas proxy compartilhado, não).
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: private, max-age=86400');
        header("Content-Type: {$type}");
        header('X-Accel-Redirect: /_guide-videos/' . rawurlencode($file));
    }
}

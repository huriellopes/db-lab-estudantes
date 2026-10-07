<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Actions\Admin\CreateAdminUserAction;
use App\Actions\Admin\DestroyAdminSchemaAction;
use App\Actions\Admin\DestroyAdminUserAction;
use App\Actions\Admin\EditAdminUserAction;
use App\Actions\Admin\IndexAdminUsersAction;
use App\Actions\Admin\ResetAdminUserPasswordAction;
use App\Actions\Admin\RestoreAdminUserAction;
use App\Actions\Admin\ShowAdminDashboardAction;
use App\Actions\Admin\ShowAdminSchemasAction;
use App\Actions\Admin\StoreAdminUserAction;
use App\Actions\Admin\ToggleAdminUserActiveAction;
use App\Actions\Admin\TrashAdminUsersAction;
use App\Actions\Admin\UpdateAdminUserAction;
use App\Actions\Admin\UpdateAdminUserRoleAction;
use App\Actions\Auth\CsrfTokenAction;
use App\Actions\Auth\LoginAction;
use App\Actions\Auth\LogoutAction;
use App\Actions\Auth\RedirectHomeAction;
use App\Actions\Auth\RegisterAction;
use App\Actions\Auth\ShowLoginAction;
use App\Actions\Auth\ShowRegisterAction;
use App\Actions\Connection\ShowConnectionAction;
use App\Actions\Dashboard\ShowDashboardAction;
use App\Actions\ErDiagram\DestroyErDiagramAction;
use App\Actions\ErDiagram\ShowErDiagramAction;
use App\Actions\ErDiagram\ShowErDiagramLabAction;
use App\Actions\ErDiagram\StoreErDiagramAction;
use App\Actions\ErDiagram\UpdateErDiagramAction;
use App\Actions\Guide\ShowGuideIndexAction;
use App\Actions\Guide\ShowGuideTopicAction;
use App\Actions\PasswordReset\ResetPasswordAction;
use App\Actions\PasswordReset\SendResetLinkAction;
use App\Actions\PasswordReset\ShowForgotPasswordAction;
use App\Actions\PasswordReset\ShowResetPasswordAction;
use App\Actions\Profile\EditProfileAction;
use App\Actions\Profile\UpdateMysqlLoginAction;
use App\Actions\Profile\UpdatePasswordAction;
use App\Actions\Profile\UpdateProfileAction;
use App\Actions\SavedQuery\DestroySavedQueryAction;
use App\Actions\SavedQuery\StoreSavedQueryAction;
use App\Actions\Schema\DestroySchemaAction;
use App\Actions\Schema\StoreSchemaAction;
use App\Actions\SqlConsole\ConfirmMysqlPasswordAction;
use App\Actions\SqlConsole\RunSqlAction;
use App\Actions\Student\DestroyStudentAction;
use App\Actions\Student\EditStudentAction;
use App\Actions\Student\IndexStudentsAction;
use App\Actions\Student\ResetStudentPasswordAction;
use App\Actions\Student\ToggleStudentActiveAction;
use App\Actions\Student\UpdateStudentAction;
use App\Core\Auth;
use App\Core\Router;
use App\Core\View;
use App\Support\Csrf;
use App\Support\RequestScheme;
use Dotenv\Dotenv;

// Rede de segurança final: qualquer exceção/erro não capturado por uma action/controller
// vira uma página 500 normal em vez do stack trace cru do PHP (display_errors já fica Off
// — ver docker/php-hardening.ini — isso aqui é só pra mostrar algo decente no lugar do
// branco). O detalhe real do erro vai pro log (stderr, capturado pelo supervisord/`docker
// logs`), nunca pra resposta.
set_exception_handler(static function (Throwable $e): void {
    error_log('Exceção não capturada: ' . $e);
    http_response_code(500);
    echo View::render('errors/500');
});

// Só é usado fora do Docker (ex.: `php -S localhost:8000 -t public`), já que em
// produção/dev com docker-compose as variáveis já chegam via `environment:`.
if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

// Cookie de sessão travado: HttpOnly (JS não lê), SameSite=Lax (mitiga CSRF cross-site
// básico) e Secure só quando a requisição realmente chegou por HTTPS (RequestScheme —
// necessário porque o TLS termina no Nginx Proxy Manager, não no PHP-FPM; em dev, sobre
// HTTP puro, Secure ligado quebraria o cookie por completo).
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => RequestScheme::isHttps($_SERVER),
    'samesite' => 'Lax',
]);
session_start();

// Revalida a sessão logada contra o banco (conta desativada/excluída, senha trocada, papel
// alterado, timeout) — ver App\Core\Auth::enforceSession(). Antes do attemptRememberLogin
// de propósito: uma sessão que acabou de expirar pode ser reaberta pelo cookie na sequência.
Auth::enforceSession();

// Sem sessão ativa, mas com um cookie "lembrar de mim" válido? Reabre sozinho — ver
// App\Core\Auth::attemptRememberLogin(). Roda uma vez só, aqui, antes de qualquer rota.
Auth::attemptRememberLogin();

// Toda requisição POST precisa do token CSRF da própria sessão — via campo _csrf (forms
// clássicos, ver csrf_field() no Twig) ou header X-CSRF-Token (Axios, ver resources/js/app.js).
// Verificado aqui, antes do roteamento, pra não precisar repetir em cada action/controller.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    if (!Csrf::verify(is_string($submitted) ? $submitted : null)) {
        http_response_code(419);

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            // Na prática quase ninguém vê essa mensagem: o interceptor Axios (ver
            // resources/js/app.js) busca um token novo em GET /csrf-token e repete a
            // requisição sozinho antes de desistir — só chega aqui de novo se a sessão
            // tiver mesmo caído (não só o token), daí o login de novo é obrigatório.
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Sessão expirada — tente de novo em instantes.',
            ]);
        } else {
            echo View::render('errors/419');
        }

        exit;
    }
}

$router = new Router();

$router->get('/', RedirectHomeAction::class);
$router->get('/login', ShowLoginAction::class);
$router->post('/login', LoginAction::class);
$router->get('/register', ShowRegisterAction::class);
$router->post('/register', RegisterAction::class);
$router->post('/logout', LogoutAction::class);
$router->get('/esqueci-senha', ShowForgotPasswordAction::class);
$router->post('/esqueci-senha', SendResetLinkAction::class);
$router->get('/redefinir-senha/{token}', ShowResetPasswordAction::class);
$router->post('/redefinir-senha', ResetPasswordAction::class);

$router->get('/dashboard', ShowDashboardAction::class);
$router->post('/schemas', StoreSchemaAction::class);
$router->post('/schemas/delete', DestroySchemaAction::class);
$router->post('/dashboard/sql', RunSqlAction::class);
$router->post('/dashboard/sql/confirmar-senha', ConfirmMysqlPasswordAction::class);
$router->post('/consultas-salvas', StoreSavedQueryAction::class);
$router->post('/consultas-salvas/excluir', DestroySavedQueryAction::class);

// Sem login exigido de propósito: o form de login também depende de CSRF, e é
// exatamente aí que uma aba ficar aberta tempo demais faria mais falta (ver Auth::requireLogin).
$router->get('/csrf-token', CsrfTokenAction::class);

$router->get('/profile', EditProfileAction::class);
$router->post('/profile', UpdateProfileAction::class);
$router->post('/profile/password', UpdatePasswordAction::class);
$router->post('/profile/mysql-login', UpdateMysqlLoginAction::class);

$router->get('/conectar', ShowConnectionAction::class);

$router->get('/guia', ShowGuideIndexAction::class);
$router->get('/guia/{slug}', ShowGuideTopicAction::class);

$router->get('/laboratorio/modelagem', ShowErDiagramLabAction::class);
$router->get('/laboratorio/modelagem/{id}', ShowErDiagramAction::class);
$router->post('/laboratorio/modelagem', StoreErDiagramAction::class);
$router->post('/laboratorio/modelagem/{id}', UpdateErDiagramAction::class);
$router->post('/laboratorio/modelagem/{id}/excluir', DestroyErDiagramAction::class);

// Professor (e admin): gestão de contas de aluno.
$router->get('/professor/alunos', IndexStudentsAction::class);
$router->get('/professor/alunos/{id}/editar', EditStudentAction::class);
$router->post('/professor/alunos/{id}', UpdateStudentAction::class);
$router->post('/professor/alunos/{id}/senha', ResetStudentPasswordAction::class);
$router->post('/professor/alunos/{id}/status', ToggleStudentActiveAction::class);
$router->post('/professor/alunos/{id}/excluir', DestroyStudentAction::class);

// Admin: controle total sobre usuários, papéis e schemas.
$router->get('/admin', ShowAdminDashboardAction::class);
$router->get('/admin/usuarios', IndexAdminUsersAction::class);
$router->get('/admin/usuarios/novo', CreateAdminUserAction::class);
$router->post('/admin/usuarios', StoreAdminUserAction::class);
$router->get('/admin/usuarios/lixeira', TrashAdminUsersAction::class);
$router->get('/admin/usuarios/{id}/editar', EditAdminUserAction::class);
$router->post('/admin/usuarios/{id}', UpdateAdminUserAction::class);
$router->post('/admin/usuarios/{id}/papel', UpdateAdminUserRoleAction::class);
$router->post('/admin/usuarios/{id}/status', ToggleAdminUserActiveAction::class);
$router->post('/admin/usuarios/{id}/senha', ResetAdminUserPasswordAction::class);
$router->post('/admin/usuarios/{id}/excluir', DestroyAdminUserAction::class);
$router->post('/admin/usuarios/{id}/restaurar', RestoreAdminUserAction::class);
$router->get('/admin/schemas', ShowAdminSchemasAction::class);
$router->post('/admin/schemas/excluir', DestroyAdminSchemaAction::class);

// parse_url() pode devolver null/false para uma REQUEST_URI malformada; com
// strict_types, isso não pode ser passado direto para o parâmetro string do dispatch().
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router->dispatch($_SERVER['REQUEST_METHOD'], is_string($path) ? $path : '/');

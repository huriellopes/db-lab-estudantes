<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Actions\Admin\ClearTwigCacheAction;
use App\Actions\Admin\CreateAdminUserAction;
use App\Actions\Admin\DestroyAdminSchemaAction;
use App\Actions\Admin\DestroyAdminUserAction;
use App\Actions\Admin\DestroyBackupAction;
use App\Actions\Admin\DownloadBackupAction;
use App\Actions\Admin\EditAdminUserAction;
use App\Actions\Admin\IndexAdminUsersAction;
use App\Actions\Admin\IndexAuditLogsAction;
use App\Actions\Admin\IndexBackupsAction;
use App\Actions\Admin\IndexDeletedModelsAction;
use App\Actions\Admin\KeepDeletedBatchAction;
use App\Actions\Admin\LiveLogsFeedAction;
use App\Actions\Admin\PruneBackupsAction;
use App\Actions\Admin\PruneLogsAction;
use App\Actions\Admin\PurgeDeletedBatchAction;
use App\Actions\Admin\RedirectTrashAction;
use App\Actions\Admin\RefreshHealthAction;
use App\Actions\Admin\ResetAdminUserPasswordAction;
use App\Actions\Admin\ResetOpcacheAction;
use App\Actions\Admin\RestoreDeletedBatchAction;
use App\Actions\Admin\ShowAdminDashboardAction;
use App\Actions\Admin\ShowAdminLogsAction;
use App\Actions\Admin\ShowAdminMaintenanceAction;
use App\Actions\Admin\ShowAdminSchemasAction;
use App\Actions\Admin\ShowLiveLogsAction;
use App\Actions\Admin\StoreAdminUserAction;
use App\Actions\Admin\StoreBackupAction;
use App\Actions\Admin\ToggleAdminUserActiveAction;
use App\Actions\Admin\ToggleMaintenanceModeAction;
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
use App\Actions\Institution\AddInstitutionMemberAction;
use App\Actions\Institution\DestroyInstitutionAction;
use App\Actions\Institution\IndexInstitutionsAction;
use App\Actions\Institution\InstitutionCandidatesAction;
use App\Actions\Institution\InstitutionCodeAction;
use App\Actions\Institution\RemoveInstitutionMemberAction;
use App\Actions\Institution\ShowInstitutionAction;
use App\Actions\Institution\StoreInstitutionAction;
use App\Actions\Institution\UpdateInstitutionAction;
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
use App\Actions\SchoolClass\AddClassMemberAction;
use App\Actions\SchoolClass\ClassCandidatesAction;
use App\Actions\SchoolClass\ClassCodeAction;
use App\Actions\SchoolClass\DestroyClassAction;
use App\Actions\SchoolClass\IndexClassesAction;
use App\Actions\SchoolClass\JoinByCodeAction;
use App\Actions\SchoolClass\LeaveClassAction;
use App\Actions\SchoolClass\RemoveClassMemberAction;
use App\Actions\SchoolClass\ShowClassAction;
use App\Actions\SchoolClass\StoreClassAction;
use App\Actions\SchoolClass\UpdateClassAction;
use App\Actions\SqlConsole\ConfirmMysqlPasswordAction;
use App\Actions\SqlConsole\RunSqlAction;
use App\Actions\Student\DestroyStudentAction;
use App\Actions\Student\EditStudentAction;
use App\Actions\Student\IndexStudentsAction;
use App\Actions\Student\ResetStudentPasswordAction;
use App\Actions\Student\ToggleStudentActiveAction;
use App\Actions\Student\UpdateStudentAction;
use App\Actions\StudentDatabase\IndexStudentDatabasesAction;
use App\Actions\StudentDatabase\InsertStudentRowAction;
use App\Actions\StudentDatabase\ShowStudentDatabaseAction;
use App\Actions\StudentDatabase\ShowStudentTableAction;
use App\Actions\StudentDatabase\UpdateStudentRowAction;
use App\Core\Auth;
use App\Core\Router;
use App\Core\View;
use App\Services\Maintenance;
use App\Support\Csrf;
use App\Support\ErrorLogger;
use App\Support\RequestScheme;
use Dotenv\Dotenv;

// Rede de segurança final: qualquer exceção/erro não capturado por uma action/controller
// vira uma página 500 normal em vez do stack trace cru do PHP (display_errors já fica Off
// — ver docker/php-hardening.ini — isso aqui é só pra mostrar algo decente no lugar do
// branco). O detalhe real do erro vai pro log (stderr, capturado pelo supervisord/`docker
// logs`), nunca pra resposta.
set_exception_handler(static function (Throwable $e): void {
    error_log('Exceção não capturada: ' . $e);
    ErrorLogger::exception($e, 'critical');
    http_response_code(500);
    echo View::render('errors/500');
});

// Warnings/notices não derrubam a requisição, mas costumam ser o primeiro sinal de bug —
// vão pro log que o admin vê em /admin/logs. Retorna false: o tratamento padrão do PHP
// (error_log pro stderr) continua acontecendo.
set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
    if ((error_reporting() & $errno) !== 0) {
        ErrorLogger::message($errno & (E_WARNING | E_USER_WARNING) ? 'warning' : 'notice', $message, "{$file}:{$line}");
    }

    return false;
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

// Modo manutenção (ligado em /admin/manutencao): quem não é admin recebe 503 em tudo,
// menos login/logout/token CSRF — senão nem o próprio admin conseguiria entrar pra desligar.
// Antes do CSRF e do roteador: nenhuma ação de aluno/professor chega a rodar.
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (Maintenance::isOn() && !Maintenance::allows(is_string($requestPath) ? $requestPath : '/', Auth::isAdmin())) {
    http_response_code(503);
    header('Retry-After: 300');
    $mode = Maintenance::mode();

    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'O lab está em manutenção — tente de novo em alguns minutos.']);
    } else {
        echo View::render('errors/503', ['mode' => $mode]);
    }

    exit;
}

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

$router->get('/turmas', IndexClassesAction::class);
$router->post('/turmas', StoreClassAction::class);
$router->get('/turmas/{id}', ShowClassAction::class);
$router->post('/turmas/{id}', UpdateClassAction::class);
$router->get('/turmas/{id}/candidatos', ClassCandidatesAction::class);
$router->post('/turmas/{id}/membros', AddClassMemberAction::class);
$router->post('/turmas/{id}/sair', LeaveClassAction::class);
$router->post('/turmas/{id}/codigo', ClassCodeAction::class);
$router->post('/entrar-com-codigo', JoinByCodeAction::class);
$router->post('/turmas/{id}/membros/{member}/remover', RemoveClassMemberAction::class);
$router->post('/turmas/{id}/excluir', DestroyClassAction::class);

// Professor (e admin): gestão de contas de aluno.
$router->get('/professor/alunos', IndexStudentsAction::class);
$router->get('/professor/alunos/{id}/editar', EditStudentAction::class);
$router->post('/professor/alunos/{id}', UpdateStudentAction::class);
$router->post('/professor/alunos/{id}/senha', ResetStudentPasswordAction::class);
$router->post('/professor/alunos/{id}/status', ToggleStudentActiveAction::class);
$router->post('/professor/alunos/{id}/excluir', DestroyStudentAction::class);

// Professor: bancos dos alunos das instituições dele (ver/inserir/alterar; excluir não — ver ProfessorGrants).
$router->get('/professor/bancos', IndexStudentDatabasesAction::class);
$router->get('/professor/bancos/{banco}', ShowStudentDatabaseAction::class);
$router->get('/professor/bancos/{banco}/{tabela}', ShowStudentTableAction::class);
$router->post('/professor/bancos/{banco}/{tabela}/linhas', InsertStudentRowAction::class);
$router->post('/professor/bancos/{banco}/{tabela}/editar', UpdateStudentRowAction::class);

// Admin: controle total sobre usuários, papéis e schemas.
$router->get('/admin', ShowAdminDashboardAction::class);
$router->get('/admin/usuarios', IndexAdminUsersAction::class);
$router->get('/admin/usuarios/novo', CreateAdminUserAction::class);
$router->post('/admin/usuarios', StoreAdminUserAction::class);
$router->get('/admin/usuarios/lixeira', RedirectTrashAction::class);
$router->get('/admin/usuarios/{id}/editar', EditAdminUserAction::class);
$router->post('/admin/usuarios/{id}', UpdateAdminUserAction::class);
$router->post('/admin/usuarios/{id}/papel', UpdateAdminUserRoleAction::class);
$router->post('/admin/usuarios/{id}/status', ToggleAdminUserActiveAction::class);
$router->post('/admin/usuarios/{id}/senha', ResetAdminUserPasswordAction::class);
$router->post('/admin/usuarios/{id}/excluir', DestroyAdminUserAction::class);
$router->get('/admin/schemas', ShowAdminSchemasAction::class);
$router->post('/admin/schemas/excluir', DestroyAdminSchemaAction::class);
$router->get('/admin/auditoria', IndexAuditLogsAction::class);
$router->get('/admin/logs', ShowAdminLogsAction::class);
$router->get('/admin/logs/ao-vivo', ShowLiveLogsAction::class);
$router->get('/admin/logs/ao-vivo/feed', LiveLogsFeedAction::class);
$router->get('/admin/backups', IndexBackupsAction::class);
$router->post('/admin/backups', StoreBackupAction::class);
$router->get('/admin/backups/{name}', DownloadBackupAction::class);
$router->post('/admin/backups/{name}/excluir', DestroyBackupAction::class);
$router->get('/admin/instituicoes', IndexInstitutionsAction::class);
$router->post('/admin/instituicoes', StoreInstitutionAction::class);
$router->get('/admin/instituicoes/{id}', ShowInstitutionAction::class);
$router->post('/admin/instituicoes/{id}', UpdateInstitutionAction::class);
$router->post('/admin/instituicoes/{id}/codigo', InstitutionCodeAction::class);
$router->get('/admin/instituicoes/{id}/candidatos', InstitutionCandidatesAction::class);
$router->post('/admin/instituicoes/{id}/membros', AddInstitutionMemberAction::class);
$router->post('/admin/instituicoes/{id}/membros/{member}/remover', RemoveInstitutionMemberAction::class);
$router->post('/admin/instituicoes/{id}/excluir', DestroyInstitutionAction::class);
$router->get('/admin/excluidos', IndexDeletedModelsAction::class);
$router->post('/admin/excluidos/{batch}/restaurar', RestoreDeletedBatchAction::class);
$router->post('/admin/excluidos/{batch}/excluir', PurgeDeletedBatchAction::class);
$router->post('/admin/excluidos/{batch}/manter', KeepDeletedBatchAction::class);
$router->get('/admin/manutencao', ShowAdminMaintenanceAction::class);
$router->post('/admin/manutencao/status', RefreshHealthAction::class);
$router->post('/admin/manutencao/cache-twig', ClearTwigCacheAction::class);
$router->post('/admin/manutencao/opcache', ResetOpcacheAction::class);
$router->post('/admin/manutencao/logs', PruneLogsAction::class);
$router->post('/admin/manutencao/backups', PruneBackupsAction::class);
$router->post('/admin/manutencao/modo', ToggleMaintenanceModeAction::class);

// parse_url() pode devolver null/false para uma REQUEST_URI malformada; com
// strict_types, isso não pode ser passado direto para o parâmetro string do dispatch().
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router->dispatch($_SERVER['REQUEST_METHOD'], is_string($path) ? $path : '/');

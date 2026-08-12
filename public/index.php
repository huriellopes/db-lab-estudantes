<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\ConnectionController;
use App\Controllers\DashboardController;
use App\Controllers\PasswordResetController;
use App\Controllers\ProfileController;
use App\Controllers\SchemaController;
use App\Controllers\SqlConsoleController;
use App\Controllers\StudentController;
use App\Core\Router;
use App\Core\View;
use App\Support\Csrf;
use App\Support\RequestScheme;
use Dotenv\Dotenv;

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

// Toda requisição POST precisa do token CSRF da própria sessão — via campo _csrf (forms
// clássicos, ver csrf_field() no Twig) ou header X-CSRF-Token (Axios, ver resources/js/app.js).
// Verificado aqui, antes do roteamento, pra não precisar repetir em cada controller.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);

    if (!Csrf::verify(is_string($submitted) ? $submitted : null)) {
        http_response_code(419);

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Sessão expirada — recarregue a página e tente de novo.',
            ]);
        } else {
            echo View::render('errors/419');
        }

        exit;
    }
}

$router = new Router();

$router->get('/', [AuthController::class, 'redirectHome']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);
$router->get('/esqueci-senha', [PasswordResetController::class, 'showForgot']);
$router->post('/esqueci-senha', [PasswordResetController::class, 'sendResetLink']);
$router->get('/redefinir-senha/{token}', [PasswordResetController::class, 'showReset']);
$router->post('/redefinir-senha', [PasswordResetController::class, 'resetPassword']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->post('/schemas', [SchemaController::class, 'store']);
$router->post('/schemas/delete', [SchemaController::class, 'destroy']);
$router->post('/dashboard/sql', [SqlConsoleController::class, 'run']);

$router->get('/profile', [ProfileController::class, 'edit']);
$router->post('/profile', [ProfileController::class, 'update']);
$router->post('/profile/password', [ProfileController::class, 'updatePassword']);
$router->post('/profile/mysql-login', [ProfileController::class, 'updateMysqlLogin']);

$router->get('/conectar', [ConnectionController::class, 'show']);

// Professor (e admin): gestão de contas de aluno.
$router->get('/professor/alunos', [StudentController::class, 'index']);
$router->get('/professor/alunos/{id}/editar', [StudentController::class, 'edit']);
$router->post('/professor/alunos/{id}', [StudentController::class, 'update']);
$router->post('/professor/alunos/{id}/senha', [StudentController::class, 'resetPassword']);
$router->post('/professor/alunos/{id}/status', [StudentController::class, 'toggleActive']);
$router->post('/professor/alunos/{id}/excluir', [StudentController::class, 'destroy']);

// Admin: controle total sobre usuários, papéis e schemas.
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/usuarios', [AdminController::class, 'users']);
$router->get('/admin/usuarios/novo', [AdminController::class, 'create']);
$router->post('/admin/usuarios', [AdminController::class, 'store']);
$router->get('/admin/usuarios/lixeira', [AdminController::class, 'trash']);
$router->get('/admin/usuarios/{id}/editar', [AdminController::class, 'edit']);
$router->post('/admin/usuarios/{id}', [AdminController::class, 'update']);
$router->post('/admin/usuarios/{id}/papel', [AdminController::class, 'updateRole']);
$router->post('/admin/usuarios/{id}/status', [AdminController::class, 'toggleActive']);
$router->post('/admin/usuarios/{id}/senha', [AdminController::class, 'resetPassword']);
$router->post('/admin/usuarios/{id}/excluir', [AdminController::class, 'destroy']);
$router->post('/admin/usuarios/{id}/restaurar', [AdminController::class, 'restore']);
$router->get('/admin/schemas', [AdminController::class, 'schemas']);
$router->post('/admin/schemas/excluir', [AdminController::class, 'destroySchema']);

// parse_url() pode devolver null/false para uma REQUEST_URI malformada; com
// strict_types, isso não pode ser passado direto para o parâmetro string do dispatch().
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router->dispatch($_SERVER['REQUEST_METHOD'], is_string($path) ? $path : '/');

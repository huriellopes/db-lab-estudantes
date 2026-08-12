<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ProfileController;
use App\Controllers\SchemaController;
use App\Controllers\StudentController;
use App\Core\Router;
use Dotenv\Dotenv;

// Só é usado fora do Docker (ex.: `php -S localhost:8000 -t public`), já que em
// produção/dev com docker-compose as variáveis já chegam via `environment:`.
if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

session_start();

$router = new Router();

$router->get('/', [AuthController::class, 'redirectHome']);
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->post('/schemas', [SchemaController::class, 'store']);
$router->post('/schemas/delete', [SchemaController::class, 'destroy']);

$router->get('/profile', [ProfileController::class, 'edit']);
$router->post('/profile', [ProfileController::class, 'update']);
$router->post('/profile/password', [ProfileController::class, 'updatePassword']);
$router->post('/profile/mysql-login', [ProfileController::class, 'updateMysqlLogin']);

// Professor (e admin): gestão de contas de aluno.
$router->get('/professor/alunos', [StudentController::class, 'index']);
$router->get('/professor/alunos/{id}/editar', [StudentController::class, 'edit']);
$router->post('/professor/alunos/{id}', [StudentController::class, 'update']);
$router->post('/professor/alunos/{id}/senha', [StudentController::class, 'resetPassword']);
$router->post('/professor/alunos/{id}/excluir', [StudentController::class, 'destroy']);

// Admin: controle total sobre usuários, papéis e schemas.
$router->get('/admin', [AdminController::class, 'index']);
$router->get('/admin/usuarios', [AdminController::class, 'users']);
$router->get('/admin/usuarios/{id}/editar', [AdminController::class, 'edit']);
$router->post('/admin/usuarios/{id}', [AdminController::class, 'update']);
$router->post('/admin/usuarios/{id}/papel', [AdminController::class, 'updateRole']);
$router->post('/admin/usuarios/{id}/senha', [AdminController::class, 'resetPassword']);
$router->post('/admin/usuarios/{id}/excluir', [AdminController::class, 'destroy']);
$router->get('/admin/schemas', [AdminController::class, 'schemas']);
$router->post('/admin/schemas/excluir', [AdminController::class, 'destroySchema']);

// parse_url() pode devolver null/false para uma REQUEST_URI malformada; com
// strict_types, isso não pode ser passado direto para o parâmetro string do dispatch().
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$router->dispatch($_SERVER['REQUEST_METHOD'], is_string($path) ? $path : '/');

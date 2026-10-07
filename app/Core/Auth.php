<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Entities\User;
use App\Models\RememberToken;
use App\Models\User as UserModel;
use App\Support\AuthenticatedUser;
use App\Support\Crypto;
use App\Support\Csrf;
use App\Support\Policy;
use App\Support\RequestScheme;
use App\Support\SessionTimeout;

final class Auth
{
    /** Nome do cookie de "lembrar de mim" — separado do cookie de sessão do PHP. */
    private const REMEMBER_COOKIE = 'remember_token';
    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function id(): ?int
    {
        return self::user()?->id;
    }

    public static function user(): ?AuthenticatedUser
    {
        $user = $_SESSION['user'] ?? null;

        return $user instanceof AuthenticatedUser ? $user : null;
    }

    /**
     * @param string|null $plainPassword Quando informada (login de verdade, não um
     *                                   refresh() de dados), fica cacheada (criptografada, ver Crypto) na sessão —
     *                                   é o que permite o console SQL do dashboard abrir uma conexão MySQL como a
     *                                   própria pessoa (ver Database::connectAs / mysqlPassword()) sem pedir a senha
     *                                   de novo a cada comando.
     */
    public static function login(User $user, ?string $plainPassword = null): void
    {
        session_regenerate_id(true);
        // Token CSRF novo junto com o ID novo: o de antes do login pode ter sido visto por
        // quem plantou a sessão (o próximo GET gera outro, ver App\Support\Csrf::token).
        unset($_SESSION[Csrf::SESSION_KEY]);

        self::storeSnapshot($user);
        $_SESSION['logged_in_at'] = time();
        $_SESSION['last_activity_at'] = time();
        if ($plainPassword !== null) {
            $_SESSION['mysql_password_enc'] = Crypto::encrypt($plainPassword);
        }
    }

    /**
     * Atualiza os dados da sessão sem exigir novo login (usado após editar o próprio perfil
     * ou trocar a própria senha). Sem session_regenerate_id de propósito: não é uma troca de
     * privilégio, e regenerar aqui só invalidaria o token CSRF das outras abas abertas.
     */
    public static function refresh(User $user): void
    {
        if (self::check()) {
            self::storeSnapshot($user);
        }
    }

    /**
     * Revalida a sessão logada contra o banco, uma vez por requisição (chamado no bootstrap,
     * public/index.php, antes de attemptRememberLogin). Antes, a sessão guardava um retrato
     * do usuário tirado no login e nunca mais olhava pro banco: desativar, excluir, rebaixar
     * o papel ou trocar a senha de alguém não afetava a sessão já aberta dessa pessoa.
     *
     * - Expirou (inatividade ou idade, ver SessionTimeout): encerra só a sessão — o cookie de
     *   "lembrar de mim", se houver, reabre uma nova logo em seguida.
     * - Conta sumiu, foi desativada ou session_version mudou (senha trocada etc.): encerra a
     *   sessão E o cookie de lembrar (os tokens no banco já foram revogados por UserManager).
     * - Senão: atualiza o retrato (papel, nome, login...) e o horário da última atividade.
     */
    public static function enforceSession(): void
    {
        $sessionUser = self::user();
        if ($sessionUser === null) {
            return;
        }

        $now = time();
        if (SessionTimeout::isExpired($now, self::sessionInt('last_activity_at'), self::sessionInt('logged_in_at'))) {
            self::endSession();

            return;
        }

        $user = UserModel::find($sessionUser->id);
        if ($user === null || !$user->active || $user->sessionVersion !== (self::sessionInt('session_version') ?? 0)) {
            self::clearRememberCookie();
            self::endSession();

            return;
        }

        self::storeSnapshot($user);
        $_SESSION['last_activity_at'] = $now;
        $_SESSION['logged_in_at'] ??= $now;
    }

    /**
     * Depois que a própria pessoa troca a senha, UserManager::resetPassword revoga os cookies
     * de "lembrar de mim" de todos os dispositivos — inclusive deste. Se este navegador tinha
     * um, emite outro na hora, pra quem trocou a senha não perder o "manter conectado" aqui.
     */
    public static function keepRememberedDevice(int $userId): void
    {
        $plainToken = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($plainToken) && $plainToken !== '') {
            self::remember($userId);
        }
    }

    /**
     * Chamado só quando a pessoa marca "Manter conectado" no login — emite um token novo
     * (App\Models\RememberToken) e guarda num cookie separado do de sessão, próprio pra
     * sobreviver ao navegador fechar (a sessão do PHP em si nunca teve "lembrar", ver
     * session_set_cookie_params em public/index.php).
     */
    public static function remember(int $userId): void
    {
        self::setRememberCookie(RememberToken::issueFor($userId));
    }

    /**
     * Se não há sessão ativa mas existe um cookie "lembrar de mim" válido, reabre a
     * sessão sozinha (sem pedir senha) e troca o token por um novo (ver
     * RememberToken::rotate — fecha a janela de uso caso o cookie tenha vazado). Chamado
     * uma vez só, no bootstrap (public/index.php) antes do roteamento — assim check()/
     * user() continuam uma leitura pura da sessão, sem efeito colateral escondido em
     * todo lugar que os chama.
     *
     * De propósito NÃO cacheia a senha MySQL (Auth::login() sem $plainPassword): quem
     * abriu a sessão foi o cookie, não a pessoa digitando a senha — o console SQL do
     * dashboard só volta a funcionar depois de um login de verdade (mensagem já existente
     * em SqlConsoleController cobre esse caso).
     */
    public static function attemptRememberLogin(): void
    {
        if (self::check()) {
            return;
        }

        $plainToken = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (!is_string($plainToken) || $plainToken === '') {
            return;
        }

        $record = RememberToken::findValid($plainToken);
        if ($record === null) {
            self::clearRememberCookie();

            return;
        }

        $user = UserModel::find($record->userId);
        if ($user === null || !$user->active) {
            self::clearRememberCookie();

            return;
        }

        self::login($user);
        UserModel::touchLastLogin($user->id);
        self::setRememberCookie(RememberToken::rotate($record->id, $record->userId));
    }

    /** Chamado depois que a pessoa troca a própria senha, pra manter o cache em dia. */
    public static function refreshMysqlPassword(string $plainPassword): void
    {
        $_SESSION['mysql_password_enc'] = Crypto::encrypt($plainPassword);
    }

    /**
     * Senha MySQL em texto puro da pessoa logada, descriptografada na hora — null se
     * nunca foi cacheada (ex.: sessão aberta antes dessa feature existir; a pessoa
     * precisa só logar de novo) ou se o valor guardado estiver corrompido.
     */
    public static function mysqlPassword(): ?string
    {
        $encrypted = $_SESSION['mysql_password_enc'] ?? null;

        return is_string($encrypted) ? Crypto::decrypt($encrypted) : null;
    }

    public static function logout(): void
    {
        $plainToken = $_COOKIE[self::REMEMBER_COOKIE] ?? null;
        if (is_string($plainToken) && $plainToken !== '') {
            // Só derruba o token DESSE dispositivo — logout num navegador não desconecta
            // os outros (ver App\Models\RememberToken).
            RememberToken::revoke($plainToken);
        }
        self::clearRememberCookie();

        $_SESSION = [];
        session_destroy();
    }

    public static function isAdmin(): bool
    {
        return Policy::isAdmin(self::user());
    }

    public static function isProfessor(): bool
    {
        return Policy::isProfessor(self::user());
    }

    public static function isAluno(): bool
    {
        return Policy::isAluno(self::user());
    }

    public static function canManageStudents(): bool
    {
        return Policy::canManageStudents(self::user());
    }

    /**
     * Chamada de guarda no início de toda action que exige login. Pra requisição AJAX
     * (Alpine/Axios, ver X-Requested-With em resources/js/app.js), devolve JSON em vez de
     * redirecionar: um redirect vira 200 com o HTML da tela de login no corpo assim que o
     * XHR o segue sozinho, e quem chamou (ex.: o console SQL) acaba tentando ler aquele
     * HTML como se fosse o JSON de resultado — sem essa distinção, a sessão cair no meio de
     * um comando dava uma tela em branco/quebrada em vez de um erro explicando o que houve.
     */
    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }

        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Sua sessão expirou. Faça login de novo pra continuar — o que você digitou fica guardado neste navegador.',
            ]);
            exit;
        }

        header('Location: /login');
        exit;
    }

    public static function requireProfessorOrAdmin(): void
    {
        self::requireLogin();

        if (!self::canManageStudents()) {
            self::forbidden();
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();

        if (!self::isAdmin()) {
            self::forbidden();
        }
    }

    private static function storeSnapshot(User $user): void
    {
        $_SESSION['user'] = AuthenticatedUser::fromEntity($user);
        $_SESSION['session_version'] = $user->sessionVersion;
    }

    private static function sessionInt(string $key): ?int
    {
        $value = $_SESSION[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * Sai da conta sem destruir a sessão PHP em si. Mantém só o token CSRF: a verificação de
     * CSRF do bootstrap roda depois, e sem ele um POST feito logo após a sessão expirar
     * levava 419 em vez de cair no redirect pro login (Auth::requireLogin).
     */
    private static function endSession(): void
    {
        $csrf = $_SESSION[Csrf::SESSION_KEY] ?? null;
        $_SESSION = [];
        if (is_string($csrf)) {
            $_SESSION[Csrf::SESSION_KEY] = $csrf;
        }
        session_regenerate_id(true);
    }

    private static function forbidden(): never
    {
        http_response_code(403);
        echo View::render('errors/403');
        exit;
    }

    /** Mesmas flags de segurança do cookie de sessão (ver public/index.php): HttpOnly,
     *  SameSite=Lax e Secure só quando a requisição já chegou por HTTPS. */
    private static function setRememberCookie(string $plainToken): void
    {
        setcookie(self::REMEMBER_COOKIE, $plainToken, [
            'expires' => time() + RememberToken::TTL_DAYS * 24 * 60 * 60,
            'path' => '/',
            'httponly' => true,
            'secure' => RequestScheme::isHttps($_SERVER),
            'samesite' => 'Lax',
        ]);
    }

    private static function clearRememberCookie(): void
    {
        setcookie(self::REMEMBER_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'secure' => RequestScheme::isHttps($_SERVER),
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::REMEMBER_COOKIE]);
    }
}

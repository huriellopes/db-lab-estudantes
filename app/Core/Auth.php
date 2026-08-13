<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Entities\User;
use App\Models\RememberToken;
use App\Models\User as UserModel;
use App\Support\AuthenticatedUser;
use App\Support\Crypto;
use App\Support\Policy;
use App\Support\RequestScheme;

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

        $_SESSION['user'] = AuthenticatedUser::fromEntity($user);
        if ($plainPassword !== null) {
            $_SESSION['mysql_password_enc'] = Crypto::encrypt($plainPassword);
        }
    }

    /** Atualiza os dados da sessão sem exigir novo login (usado após editar o próprio perfil). */
    public static function refresh(User $user): void
    {
        if (self::check()) {
            self::login($user);
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

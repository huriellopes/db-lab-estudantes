<?php

declare(strict_types=1);

namespace App\Core;

use App\Support\ByteSize;
use App\Support\Csrf;
use App\Support\PasswordPolicy;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Mecanismo de renderização: todo HTML fica em templates .twig (app/Views), sem PHP
 * misturado — os controllers só passam dados, o template só exibe. Twig também cuida de
 * herança de layout (`{% extends %}`) e escapa tudo por padrão (proteção contra XSS).
 */
final class View
{
    private static ?Environment $twig = null;

    public static function render(string $template, array $data = []): string
    {
        return self::twig()->render("{$template}.twig", $data);
    }

    private static function twig(): Environment
    {
        if (self::$twig !== null) {
            return self::$twig;
        }

        $loader = new FilesystemLoader(dirname(__DIR__) . '/Views');
        $cacheDir = dirname(__DIR__, 2) . '/storage/twig-cache';

        $twig = new Environment($loader, [
            'cache' => is_writable(dirname($cacheDir)) ? $cacheDir : false,
            'autoescape' => 'html',
            'strict_variables' => false,
            // Sem isso, o cache do Twig por padrão só invalida em modo debug — um
            // template editado podia continuar servindo a versão antiga compilada.
            // O custo é um stat() por template a cada request, irrelevante aqui.
            'auto_reload' => true,
        ]);

        self::registerGlobals($twig);
        self::registerFunctions($twig);

        return self::$twig = $twig;
    }

    private static function registerGlobals(Environment $twig): void
    {
        $twig->addGlobal('app_name', 'DB Lab Estudantes');
        // minlength dos campos de senha nova — mesma regra do servidor (PasswordPolicy).
        $twig->addGlobal('password_min_length', PasswordPolicy::MIN_LENGTH);
    }

    private static function registerFunctions(Environment $twig): void
    {
        $twig->addFunction(new TwigFunction('logged_in', [Auth::class, 'check']));
        $twig->addFunction(new TwigFunction('auth_user', [Auth::class, 'user']));
        $twig->addFunction(new TwigFunction('is_admin', [Auth::class, 'isAdmin']));
        $twig->addFunction(new TwigFunction('is_professor', [Auth::class, 'isProfessor']));
        $twig->addFunction(new TwigFunction('is_aluno', [Auth::class, 'isAluno']));
        $twig->addFunction(new TwigFunction('can_manage_students', [Auth::class, 'canManageStudents']));
        $twig->addFunction(new TwigFunction('flash', [Flash::class, 'get']));

        $twig->addFunction(new TwigFunction('vite', [Vite::class, 'tags'], ['is_safe' => ['html']]));

        $twig->addFunction(new TwigFunction('csrf_token', [Csrf::class, 'token']));
        $twig->addFunction(new TwigFunction(
            'csrf_field',
            static fn (): string => '<input type="hidden" name="_csrf" value="' . htmlspecialchars(Csrf::token(), ENT_QUOTES, 'UTF-8') . '">',
            ['is_safe' => ['html']],
        ));

        $twig->addFilter(new TwigFilter('bytes', [ByteSize::class, 'format']));
    }
}

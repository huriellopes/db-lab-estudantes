<?php

namespace App\Core;

use App\Support\Policy;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Mecanismo de renderização: todo HTML fica em templates .twig (app/Views), sem PHP
 * misturado — os controllers só passam dados, o template só exibe. Twig também cuida de
 * herança de layout (`{% extends %}`) e escapa tudo por padrão (proteção contra XSS).
 */
class View
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
        $isProduction = Config::get('APP_ENV', 'production') === 'production';

        $twig = new Environment($loader, [
            'cache' => $isProduction && is_writable(dirname($cacheDir)) ? $cacheDir : false,
            'autoescape' => 'html',
            'strict_variables' => false,
        ]);

        self::registerGlobals($twig);
        self::registerFunctions($twig);

        return self::$twig = $twig;
    }

    private static function registerGlobals(Environment $twig): void
    {
        $twig->addGlobal('app_name', 'DB Lab Estudantes');
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
    }
}

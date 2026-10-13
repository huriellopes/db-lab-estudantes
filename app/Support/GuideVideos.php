<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Vídeo-aulas curtas do guia ("pílulas" de 1–2 min). Os arquivos ficam FORA do git, no
 * volume montado em storage/guide-videos (sobem por rsync/scp), e são entregues pelo nginx
 * via X-Accel-Redirect depois do login (ver App\Actions\Guide\ServeGuideVideoAction).
 *
 * Cada vídeo é declarado aqui pelo nome-base e, por convenção, tem três arquivos:
 * `{arquivo}.mp4` (o vídeo), `{arquivo}.webp` (pôster) e `{arquivo}.vtt` (legenda pt-BR).
 * Só aparece no guia quem já tem o .mp4 e a legenda no volume, então dá pra declarar a leva
 * planejada antes de gravar: cada vídeo entra no ar sozinho quando o arquivo sobe.
 */
final class GuideVideos
{
    /**
     * Ordem = ordem da "Trilha em vídeo" (/guia/videos), que segue os passos do índice.
     *
     * @var list<array{arquivo: string, topic: string, title: string, duration: string}>
     */
    public const ALL = [
        ['arquivo' => 'modelagem-er-der-no-lab', 'topic' => 'modelagem-er', 'title' => 'Desenhando um DER no lab de modelagem', 'duration' => '2:00'],
        ['arquivo' => 'mysql-primeiro-schema', 'topic' => 'mysql', 'title' => 'Criando seu primeiro schema', 'duration' => '1:30'],
        ['arquivo' => 'mysql-console-sql', 'topic' => 'mysql', 'title' => 'Usando o console SQL', 'duration' => '1:30'],
        ['arquivo' => 'sql-ansi-select-where', 'topic' => 'sql-ansi', 'title' => 'SELECT e WHERE em 2 minutos', 'duration' => '2:00'],
        ['arquivo' => 'sql-ansi-join', 'topic' => 'sql-ansi', 'title' => 'JOIN na prática', 'duration' => '2:00'],
    ];

    /**
     * Vídeos de canais brasileiros do YouTube, curados por tópico: linguagem simples, de quem
     * explica pra iniciante. Cada um foi conferido no oEmbed do YouTube (existe e o dono
     * permite incorporar). `id` é um vídeo; `list`, uma playlist (curso inteiro). O player
     * só carrega (youtube-nocookie.com) quando o aluno clica: antes disso o navegador não
     * fala com o Google. Ver partials/guide/youtube.twig e a frame-src em docker/nginx.conf.
     *
     * @var list<array{topic: string, title: string, channel: string, duration: string, id?: string, list?: string}>
     */
    public const YOUTUBE = [
        ['topic' => 'modelagem-er', 'id' => 'XfO3TRvESBo', 'title' => 'O que é banco de dados e por que interessa aprender isso?', 'channel' => 'DBA PRO', 'duration' => '14:13'],
        ['topic' => 'modelagem-er', 'id' => 'W2Z1STnjNJo', 'title' => 'Modelo Entidade-Relacionamento e Diagrama ER', 'channel' => 'Bóson Treinamentos', 'duration' => '7:34'],
        ['topic' => 'modelagem-er', 'id' => 'FPNvKqGHe0A', 'title' => 'Diferença entre chave primária e chave estrangeira', 'channel' => 'Programação Dinâmica', 'duration' => '10:18'],
        ['topic' => 'modelagem-er', 'id' => '8CkMX2qXgdY', 'title' => 'Modelagem de dados: modelo conceitual, lógico e físico', 'channel' => 'Miriam TechCod', 'duration' => '23:34'],
        ['topic' => 'formas-normais', 'id' => 'eRaAMNjCFYw', 'title' => 'Normalização: Primeira Forma Normal', 'channel' => 'Bóson Treinamentos', 'duration' => '11:08'],
        ['topic' => 'formas-normais', 'id' => 'TOFZQ5wm1UI', 'title' => 'Normalização de dados (1FN, 2FN, 3FN)', 'channel' => 'DesCOMPlica, Oliba!', 'duration' => '17:48'],
        ['topic' => 'sql-ansi', 'id' => '_GcR9UHNCu8', 'title' => 'SQL explicado de forma simples: guia para iniciantes', 'channel' => 'Attekita Dev', 'duration' => '18:28'],
        ['topic' => 'sql-ansi', 'id' => 'Lt8Hry6N334', 'title' => 'SQL JOINs: INNER, LEFT, RIGHT e FULL com exemplos práticos', 'channel' => 'Canal TI', 'duration' => '5:44'],
        ['topic' => 'sql-ansi', 'id' => '165r4qUvp8Q', 'title' => 'Conheça os JOINs do SQL: INNER, OUTER, FULL, SEMI e ANTI JOIN', 'channel' => 'Cod3r Cursos', 'duration' => '14:35'],
        ['topic' => 'mysql', 'list' => 'PLHz_AreHm4dkBs-795Dsgvau_ekxg8g1r', 'title' => 'Curso de Banco de Dados MySQL (curso completo)', 'channel' => 'Curso em Vídeo', 'duration' => 'playlist'],
        ['topic' => 'mysql', 'id' => 'm9YPlX0fcJk', 'title' => 'Curso MySQL #03: criando o primeiro banco de dados', 'channel' => 'Curso em Vídeo', 'duration' => '27:55'],
        ['topic' => 'mysql', 'id' => 'jx2ne8iZMOA', 'title' => 'Curso MySQL #16: INNER JOIN com várias tabelas', 'channel' => 'Curso em Vídeo', 'duration' => '28:04'],
        ['topic' => 'postgresql', 'id' => 'KKxwFVk8bsg', 'title' => 'PostgreSQL: tudo o que você precisa saber', 'channel' => 'IlustraDev', 'duration' => '4:21'],
        ['topic' => 'postgresql', 'id' => 'Z_SPrzlT4Fc', 'title' => 'Introdução ao PostgreSQL', 'channel' => 'Bóson Treinamentos', 'duration' => '10:02'],
        ['topic' => 'sql-server', 'id' => '9lIeeVTTC5s', 'title' => 'O que é SQL Server? Quais são seus principais componentes?', 'channel' => 'DBA PRO', 'duration' => '6:34'],
        ['topic' => 'oracle', 'id' => 'loKMfHY5l-g', 'title' => 'SQL, T-SQL e PL/SQL: do que se tratam', 'channel' => 'Hashtag Programação', 'duration' => '11:40'],
        ['topic' => 'oracle', 'id' => '0ZkuxPuXyBg', 'title' => 'O que é o PL/SQL?', 'channel' => 'DBAOCM', 'duration' => '11:42'],
        ['topic' => 'mongodb', 'id' => 'aure5d3B88g', 'title' => 'O que é SQL e NoSQL?', 'channel' => 'Alura', 'duration' => '12:41'],
        ['topic' => 'mongodb', 'id' => '4dTI1mVLX3I', 'title' => 'MongoDB (o banco de dados NoSQL mais legal)', 'channel' => 'Código Fonte TV', 'duration' => '9:52'],
        ['topic' => 'redis', 'id' => 'wGKRuhM8Yb8', 'title' => 'O que é Redis?', 'channel' => 'Full Cycle', 'duration' => '4:04'],
        ['topic' => 'redis', 'id' => 'HMEwYxXFTjM', 'title' => 'Redis (o banco de dados em memória mais popular do mundo)', 'channel' => 'Código Fonte TV', 'duration' => '7:32'],
    ];

    /** Extensão → Content-Type de cada arquivo que um vídeo declarado pode ter. */
    public const TYPES = [
        'mp4' => 'video/mp4',
        'webp' => 'image/webp',
        'vtt' => 'text/vtt; charset=utf-8',
    ];

    /** Só pros testes: aponta pra uma pasta temporária. */
    private static ?string $dir = null;

    public static function useDirectory(?string $dir): void
    {
        self::$dir = $dir;
    }

    public static function directory(): string
    {
        return self::$dir ?? dirname(__DIR__, 2) . '/storage/guide-videos';
    }

    /**
     * Vídeos que já estão no ar (têm .mp4 e .vtt no volume), na ordem da trilha.
     *
     * @return list<array{arquivo: string, topic: string, title: string, duration: string, hasPoster: bool}>
     */
    public static function available(?string $topic = null): array
    {
        $videos = [];
        foreach (self::ALL as $video) {
            if ($topic !== null && $video['topic'] !== $topic) {
                continue;
            }
            if (!is_file(self::path("{$video['arquivo']}.mp4")) || !is_file(self::path("{$video['arquivo']}.vtt"))) {
                continue;
            }
            $videos[] = $video + ['hasPoster' => is_file(self::path("{$video['arquivo']}.webp"))];
        }

        return $videos;
    }

    /**
     * Recomendações do YouTube, na ordem dos tópicos do guia (a trilha), com as URLs prontas.
     *
     * @return list<array{topic: string, title: string, channel: string, duration: string, embed: string, watch: string}>
     */
    public static function youtube(?string $topic = null): array
    {
        $order = array_flip(array_keys(GuideTopics::ALL));
        $videos = [];
        foreach (self::YOUTUBE as $i => $video) {
            if ($topic !== null && $video['topic'] !== $topic) {
                continue;
            }
            [$embed, $watch] = isset($video['list'])
                ? ['videoseries?list=' . $video['list'], 'playlist?list=' . $video['list']]
                : [$video['id'], 'watch?v=' . $video['id']];
            $videos[] = [
                'topic' => $video['topic'],
                'title' => $video['title'],
                'channel' => $video['channel'],
                'duration' => $video['duration'],
                'embed' => 'https://www.youtube-nocookie.com/embed/' . $embed . (isset($video['list']) ? '&' : '?') . 'autoplay=1&rel=0',
                'watch' => 'https://www.youtube.com/' . $watch,
                'order' => $order[$video['topic']] * 1000 + $i,
            ];
        }
        usort($videos, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(function (array $video): array {
            unset($video['order']);

            return $video;
        }, $videos);
    }

    /**
     * Quantos vídeos (do laboratório no ar + recomendados do YouTube) cada tópico tem, pro
     * selo 🎬 do índice.
     *
     * @return array<string, int>
     */
    public static function countByTopic(): array
    {
        $counts = [];
        foreach ([...self::available(), ...self::youtube()] as $video) {
            $counts[$video['topic']] = ($counts[$video['topic']] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Content-Type do arquivo, ou null se ele não pertence a nenhum vídeo declarado. É uma
     * lista fechada (nome-base declarado + extensão conhecida), então não tem como pedir
     * outro arquivo do volume, nem sair dele com `../`.
     */
    public static function contentType(string $file): ?string
    {
        $dot = strrpos($file, '.');
        if ($dot === false) {
            return null;
        }

        $base = substr($file, 0, $dot);
        $ext = substr($file, $dot + 1);
        if (!isset(self::TYPES[$ext]) || !in_array($base, array_column(self::ALL, 'arquivo'), true)) {
            return null;
        }

        return self::TYPES[$ext];
    }

    public static function path(string $file): string
    {
        return self::directory() . '/' . $file;
    }
}

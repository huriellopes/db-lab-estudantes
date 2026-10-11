<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Dados do túnel SSH que o aluno usa pra chegar no MySQL a partir de um SGBD local
 * (DBeaver, Workbench, TablePlus...).
 *
 * Em produção a porta do MySQL não é pública: ela só escuta no 127.0.0.1 do servidor e o
 * firewall (chain DOCKER-USER) bloqueia qualquer acesso de fora. O caminho é uma conta SSH
 * compartilhada que não tem shell e só consegue encaminhar para 127.0.0.1:MYSQL_PORT
 * (`Match User` no sshd). Sem `SSH_TUNNEL_HOST`/`SSH_TUNNEL_USER` (dev local), o túnel fica
 * desligado e a página volta a mostrar a conexão direta.
 */
final class SshTunnel
{
    /** Porta local sugerida no comando `ssh -L` (3306 costuma estar ocupada por um MySQL local). */
    public const LOCAL_PORT = 3307;

    private function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly ?string $password,
        public readonly string $dbHost,
        public readonly int $dbPort,
    ) {
    }

    /** @param array<string, mixed> $env Variáveis SSH_TUNNEL_* e MYSQL_PORT (normalmente via Config). */
    public static function fromEnv(array $env): ?self
    {
        $host = self::str($env['SSH_TUNNEL_HOST'] ?? null);
        $user = self::str($env['SSH_TUNNEL_USER'] ?? null);

        if ($host === null || $user === null) {
            return null;
        }

        return new self(
            host: $host,
            port: (int) (self::str($env['SSH_TUNNEL_PORT'] ?? null) ?? 22),
            user: $user,
            password: self::str($env['SSH_TUNNEL_PASSWORD'] ?? null),
            dbHost: '127.0.0.1',
            dbPort: (int) (self::str($env['MYSQL_PORT'] ?? null) ?? 3306),
        );
    }

    public function forwardCommand(): string
    {
        $portFlag = $this->port === 22 ? '' : "-p {$this->port} ";

        return sprintf(
            'ssh -N %s-L %d:%s:%d %s@%s',
            $portFlag,
            self::LOCAL_PORT,
            $this->dbHost,
            $this->dbPort,
            $this->user,
            $this->host,
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

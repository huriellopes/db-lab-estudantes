<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resultado de uma checagem de saúde (ver App\Services\HealthCheck). `detail` é técnico
 * (latência, versão, conexões) e só aparece pro admin — o card público do /dashboard mostra
 * apenas nome + online/offline, pra não virar mapa da infraestrutura.
 */
final readonly class HealthStatus
{
    public function __construct(
        public string $name,
        public bool $ok,
        public ?int $latencyMs = null,
        public string $detail = '',
        // O que fazer quando ok=false (comando a rodar no servidor, ação do /admin/manutencao).
        // Também só pro admin, igual ao detail.
        public string $hint = '',
        // false = o lab funciona sem esse serviço (phpMyAdmin): fora do ar, o GET /health diz
        // "degraded" (200) em vez de "down" (503). Ver HealthCheck::overall().
        public bool $critical = true,
    ) {
    }

    /** @return array{name: string, ok: bool, latencyMs: ?int, detail: string, hint: string, critical: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ok' => $this->ok,
            'latencyMs' => $this->latencyMs,
            'detail' => $this->detail,
            'hint' => $this->hint,
            'critical' => $this->critical,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['name'] ?? ''),
            (bool) ($row['ok'] ?? false),
            isset($row['latencyMs']) ? (int) $row['latencyMs'] : null,
            (string) ($row['detail'] ?? ''),
            (string) ($row['hint'] ?? ''),
            (bool) ($row['critical'] ?? true),
        );
    }
}

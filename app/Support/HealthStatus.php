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
    ) {
    }

    /** @return array{name: string, ok: bool, latencyMs: ?int, detail: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'ok' => $this->ok, 'latencyMs' => $this->latencyMs, 'detail' => $this->detail];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['name'] ?? ''),
            (bool) ($row['ok'] ?? false),
            isset($row['latencyMs']) ? (int) $row['latencyMs'] : null,
            (string) ($row['detail'] ?? ''),
        );
    }
}

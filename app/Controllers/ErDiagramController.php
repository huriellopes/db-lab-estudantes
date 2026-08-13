<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\Entities\ErDiagram as ErDiagramEntity;
use App\Models\ErDiagram;
use Throwable;

/**
 * Laboratório de modelagem (MER/DER): editor visual (ver Alpine `erLab` em
 * resources/js/app.js) onde a pessoa desenha entidades/atributos/relacionamentos
 * arrastando caixas — ver /guia/modelagem-er pra teoria por trás. O diagrama em si
 * (posição das caixas, atributos, relacionamentos) é salvo como JSON puro (ver
 * App\Models\ErDiagram); esse controller só valida e faz o CRUD por conta.
 */
final class ErDiagramController extends Controller
{
    private const MAX_TITLE_LENGTH = 80;

    // Generoso o bastante pra qualquer diagrama razoável, mas barra um payload absurdo
    // vindo de alguém tentando abusar do endpoint.
    private const MAX_DATA_BYTES = 200_000;

    public function index(array $params = []): void
    {
        Auth::requireLogin();

        $this->render('lab/modelagem', [
            'pageTitle' => 'Laboratório de modelagem',
            'diagrams' => array_map(
                static fn (ErDiagramEntity $d): array => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'updatedAt' => $d->updatedAt->format('d/m/Y H:i'),
                ],
                ErDiagram::allForUser(Auth::id()),
            ),
        ]);
    }

    /** Devolve um diagrama específico em JSON (o índice só manda id/título/data, não o desenho inteiro). */
    public function show(array $params): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $diagram = $id > 0 ? ErDiagram::findOwned($id, Auth::id()) : null;

        if ($diagram === null) {
            $this->json(false, 'Diagrama não encontrado ou não pertence a você.');
        }

        $decoded = json_decode($diagram->data, associative: true);
        $this->json(true, '', [
            'diagram' => [
                'id' => $diagram->id,
                'title' => $diagram->title,
                'data' => is_array($decoded) ? $decoded : ['entities' => [], 'relationships' => []],
            ],
        ]);
    }

    public function store(array $params = []): void
    {
        Auth::requireLogin();

        [$title, $data, $error] = $this->validate();
        if ($error !== null) {
            $this->json(false, $error);
        }

        try {
            $id = ErDiagram::create(Auth::id(), $title, $data);
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('salvar o diagrama', $e));
        }

        $this->json(true, "Diagrama \"{$title}\" salvo.", [
            'id' => $id,
            'diagrams' => $this->listForResponse(),
        ]);
    }

    public function update(array $params): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $diagram = $id > 0 ? ErDiagram::findOwned($id, Auth::id()) : null;
        if ($diagram === null) {
            $this->json(false, 'Diagrama não encontrado ou não pertence a você.');
        }

        [$title, $data, $error] = $this->validate();
        if ($error !== null) {
            $this->json(false, $error);
        }

        try {
            ErDiagram::update($diagram->id, $title, $data);
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('salvar o diagrama', $e));
        }

        $this->json(true, "Diagrama \"{$title}\" salvo.", [
            'id' => $diagram->id,
            'diagrams' => $this->listForResponse(),
        ]);
    }

    public function destroy(array $params): void
    {
        Auth::requireLogin();

        $id = (int) ($params['id'] ?? 0);
        $diagram = $id > 0 ? ErDiagram::findOwned($id, Auth::id()) : null;

        if ($diagram === null) {
            $this->json(false, 'Diagrama não encontrado ou não pertence a você.');
        }

        try {
            ErDiagram::delete($diagram->id);
        } catch (Throwable $e) {
            $this->json(false, $this->genericError('excluir o diagrama', $e));
        }

        $this->json(true, "Diagrama \"{$diagram->title}\" excluído.", [
            'diagrams' => $this->listForResponse(),
        ]);
    }

    /**
     * Valida título e o JSON do diagrama que vêm do POST — comum a store()/update().
     *
     * @return array{0: string, 1: string, 2: string|null} [título, data (JSON já normalizado), mensagem de erro ou null]
     */
    private function validate(): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        $rawData = (string) ($_POST['data'] ?? '');

        if ($title === '') {
            return ['', '', 'Dê um nome pra esse diagrama antes de salvar.'];
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return ['', '', 'Nome muito longo (máximo de ' . self::MAX_TITLE_LENGTH . ' caracteres).'];
        }
        if (strlen($rawData) > self::MAX_DATA_BYTES) {
            return ['', '', 'Diagrama grande demais pra salvar.'];
        }

        $decoded = json_decode($rawData, associative: true);
        if (!is_array($decoded) || !isset($decoded['entities'], $decoded['relationships'])) {
            return ['', '', 'Diagrama inválido — tente recarregar a página.'];
        }

        // Re-serializa (em vez de gravar $rawData direto) pra nunca guardar JSON malformado
        // no banco, mesmo que o client mande algo levemente diferente do esperado.
        return [$title, json_encode($decoded), null];
    }

    /** @return list<array<string, mixed>> */
    private function listForResponse(): array
    {
        return array_map(
            static fn (ErDiagramEntity $d): array => [
                'id' => $d->id,
                'title' => $d->title,
                'updatedAt' => $d->updatedAt->format('d/m/Y H:i'),
            ],
            ErDiagram::allForUser(Auth::id()),
        );
    }

    /** @param array<string, mixed> $extra */
    private function json(bool $success, string $message, array $extra = []): never
    {
        http_response_code($success ? 200 : 422);
        header('Content-Type: application/json');
        echo json_encode(['success' => $success, 'message' => $message] + $extra);
        exit;
    }
}

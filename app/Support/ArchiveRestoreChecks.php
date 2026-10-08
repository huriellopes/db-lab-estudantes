<?php

declare(strict_types=1);

namespace App\Support;

/**
 * O que precisa ser verdade pra restaurar um lote do arquivo, como uma lista de consultas
 * "SELECT 1 ..." — o App\Services\Archiver roda cada uma e junta as mensagens das que
 * falharem. Separado em função pura pra dar pra testar sem banco. Ordem estável (a tela
 * mostra as mensagens nessa ordem).
 */
final class ArchiveRestoreChecks
{
    /**
     * @param list<array{model: string, model_id: int, label: string, values: array<string, mixed>, meta: array<string, mixed>}> $items
     * @return list<array{sql: string, params: list<scalar>, expect: string, message: string}>
     */
    public static function for(array $items): array
    {
        $usersInBatch = [];
        $institutionsInBatch = [];
        $classInstitution = [];
        foreach ($items as $item) {
            if ($item['model'] === 'class') {
                $classInstitution[$item['model_id']] = (int) $item['values']['institution_id'];
            }
            if ($item['model'] === 'user') {
                $usersInBatch[] = $item['model_id'];
            }
            if ($item['model'] === 'institution') {
                $institutionsInBatch[] = $item['model_id'];
            }
        }

        $checks = [];
        foreach ($items as $item) {
            $table = ArchiveGraph::table($item['model']);
            $v = $item['values'];
            $checks[] = self::absent("SELECT 1 FROM {$table} WHERE id = ?", [$item['model_id']], "{$item['label']}: o id {$item['model_id']} já está em uso.");

            if ($item['model'] === 'user') {
                $checks[] = self::absent('SELECT 1 FROM users WHERE email = ?', [(string) $v['email']], "O e-mail {$v['email']} já pertence a outra conta.");
                $checks[] = self::absent('SELECT 1 FROM users WHERE mysql_login = ? OR schema_prefix = ?', [(string) $v['mysql_login'], (string) $v['mysql_login']], "O username {$v['mysql_login']} já pertence a outra conta.");
                $checks[] = self::absent('SELECT 1 FROM users WHERE mysql_login = ? OR schema_prefix = ?', [(string) $v['schema_prefix'], (string) $v['schema_prefix']], "O prefixo de schema {$v['schema_prefix']} já pertence a outra conta.");
            }

            if ($item['model'] === 'schema') {
                $checks[] = self::absent('SELECT 1 FROM schemas_criados WHERE db_name = ?', [(string) $v['db_name']], "Já existe um schema chamado {$v['db_name']} registrado.");
                $checks[] = self::absent('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [(string) $v['db_name']], "Já existe um database {$v['db_name']} no MySQL (provavelmente recriado pelo console).");
                $checks[] = [
                    'sql' => 'SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?',
                    'params' => [(string) ($item['meta']['quarantine'] ?? '')],
                    'expect' => 'present',
                    'message' => "A quarentena de {$v['db_name']} não existe mais — não há dados para restaurar.",
                ];
            }

            if ($item['model'] === 'institution') {
                $checks[] = self::absent('SELECT 1 FROM institutions WHERE name = ?', [(string) $v['name']], "Já existe uma instituição chamada {$v['name']}.");
                if (($v['invite_code'] ?? null) !== null) {
                    $checks[] = self::absent('SELECT 1 FROM institutions WHERE invite_code = ?', [(string) $v['invite_code']], "O código {$v['invite_code']} já é de outra instituição.");
                }
            }

            if ($item['model'] === 'institution_member') {
                $institutionId = (int) $v['institution_id'];
                if (!in_array($institutionId, $institutionsInBatch, true)) {
                    $checks[] = [
                        'sql' => 'SELECT 1 FROM institutions WHERE id = ?',
                        'params' => [$institutionId],
                        'expect' => 'present',
                        'message' => "{$item['label']}: a instituição #{$institutionId} também está excluída — restaure a instituição primeiro.",
                    ];
                    if (($v['role'] ?? '') === 'aluno') {
                        $checks[] = self::absent('SELECT 1 FROM institution_members WHERE student_user_id = ?', [(int) $v['user_id']], "{$item['label']}: o aluno já está em outra instituição.");
                    }
                }
            }

            if ($item['model'] === 'class') {
                $institutionId = (int) $v['institution_id'];
                $checks[] = self::absent('SELECT 1 FROM classes WHERE institution_id = ? AND name = ?', [$institutionId, (string) $v['name']], "Já existe uma turma chamada {$v['name']} nesta instituição.");
                if (!in_array($institutionId, $institutionsInBatch, true)) {
                    $checks[] = [
                        'sql' => 'SELECT 1 FROM institutions WHERE id = ?',
                        'params' => [$institutionId],
                        'expect' => 'present',
                        'message' => "{$item['label']}: a instituição #{$institutionId} também está excluída — restaure a instituição primeiro.",
                    ];
                }
            }

            if ($item['model'] === 'class_member') {
                $classId = (int) $v['class_id'];
                $userId = (int) $v['user_id'];
                $notInInstitution = "{$item['label']}: a pessoa não está mais na instituição da turma.";
                if (!isset($classInstitution[$classId])) {
                    $checks[] = [
                        'sql' => 'SELECT 1 FROM classes WHERE id = ?',
                        'params' => [$classId],
                        'expect' => 'present',
                        'message' => "{$item['label']}: a turma #{$classId} também está excluída — restaure a turma primeiro.",
                    ];
                    $checks[] = [
                        'sql' => 'SELECT 1 FROM institution_members m JOIN classes c ON c.institution_id = m.institution_id WHERE c.id = ? AND m.user_id = ?',
                        'params' => [$classId, $userId],
                        'expect' => 'present',
                        'message' => $notInInstitution,
                    ];
                } elseif (!in_array($classInstitution[$classId], $institutionsInBatch, true)) {
                    $checks[] = [
                        'sql' => 'SELECT 1 FROM institution_members WHERE institution_id = ? AND user_id = ?',
                        'params' => [$classInstitution[$classId], $userId],
                        'expect' => 'present',
                        'message' => $notInInstitution,
                    ];
                }
            }

            $ownerId = isset($v['user_id']) ? (int) $v['user_id'] : null;
            if ($ownerId !== null && !in_array($ownerId, $usersInBatch, true)) {
                $checks[] = [
                    'sql' => 'SELECT 1 FROM users WHERE id = ?',
                    'params' => [$ownerId],
                    'expect' => 'present',
                    'message' => "{$item['label']}: o dono (usuário #{$ownerId}) também está excluído — restaure o usuário primeiro.",
                ];
            }
        }

        return $checks;
    }

    /**
     * @param list<scalar> $params
     * @return array{sql: string, params: list<scalar>, expect: string, message: string}
     */
    private static function absent(string $sql, array $params, string $message): array
    {
        return ['sql' => $sql, 'params' => $params, 'expect' => 'absent', 'message' => $message];
    }
}

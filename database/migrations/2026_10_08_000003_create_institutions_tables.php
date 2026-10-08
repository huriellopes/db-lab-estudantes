<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS institutions (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(120) NOT NULL UNIQUE,
                -- XXXX-XXXX (App\Support\InviteCode). NULL = cadastro por código desativado.
                invite_code VARCHAR(9) NULL UNIQUE,
                created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS institution_members (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                institution_id  INT NOT NULL,
                user_id         INT NOT NULL,
                role            ENUM('professor', 'aluno') NOT NULL,
                created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                -- Só preenchida para aluno: o UNIQUE abaixo garante 'aluno em no máximo uma
                -- instituição' no próprio MySQL (NULLs não colidem), sem corrida entre requisições.
                -- VIRTUAL, não STORED: o MySQL recusa ON DELETE CASCADE na FK de user_id quando ele
                -- é base de uma coluna gerada STORED (erro 1215).
                student_user_id INT AS (IF(role = 'aluno', user_id, NULL)) VIRTUAL,
                UNIQUE KEY uq_member (institution_id, user_id),
                UNIQUE KEY uq_student_one_institution (student_user_id),
                INDEX idx_user (user_id),
                CONSTRAINT fk_member_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE,
                CONSTRAINT fk_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS institution_members');
        $pdo->exec('DROP TABLE IF EXISTS institutions');
    }
};

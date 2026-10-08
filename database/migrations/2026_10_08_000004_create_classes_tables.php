<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS classes (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                institution_id INT NOT NULL,
                name           VARCHAR(120) NOT NULL,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_class_name (institution_id, name),
                CONSTRAINT fk_class_institution FOREIGN KEY (institution_id) REFERENCES institutions(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS class_members (
                id         INT AUTO_INCREMENT PRIMARY KEY,
                class_id   INT NOT NULL,
                user_id    INT NOT NULL,
                -- professor = responsável pela turma (pode editá-la); aluno = participa.
                role       ENUM('professor', 'aluno') NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_class_member (class_id, user_id),
                INDEX idx_user (user_id),
                CONSTRAINT fk_class_member_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
                CONSTRAINT fk_class_member_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS class_members');
        $pdo->exec('DROP TABLE IF EXISTS classes');
    }
};

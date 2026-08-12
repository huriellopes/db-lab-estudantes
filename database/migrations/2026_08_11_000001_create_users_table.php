<?php

declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                name           VARCHAR(100) NOT NULL,
                email          VARCHAR(150) NOT NULL UNIQUE,
                password_hash  VARCHAR(255) NOT NULL,
                role           ENUM(\'aluno\', \'professor\', \'admin\') NOT NULL,
                -- mysql_login funciona como "username": escolhido pela pessoa no cadastro,
                -- usado tanto para logar na app (e-mail OU username) quanto como login
                -- MySQL/phpMyAdmin.
                mysql_login    VARCHAR(32) NOT NULL UNIQUE,
                active         TINYINT(1) NOT NULL DEFAULT 1,
                created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                -- Soft delete, como o SoftDeletes do Laravel: "excluir" só marca
                -- deleted_at — não apaga a linha nem os databases da pessoa.
                deleted_at     TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS users');
    }
};

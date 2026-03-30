<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

final class Schema
{
    public function create(PDO $pdo): void
    {
        foreach ($this->statements() as $sql) {
            $pdo->exec($sql);
        }
    }

    private function statements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS settings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `key` VARCHAR(100) NOT NULL UNIQUE,
                `value` TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS admins (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                discord_user_id VARCHAR(32) NOT NULL UNIQUE,
                username VARCHAR(120) NULL,
                display_name VARCHAR(120) NULL,
                email VARCHAR(190) NULL,
                role VARCHAR(50) NOT NULL DEFAULT 'super_admin',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS install_bootstrap (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                setup_token CHAR(64) NOT NULL UNIQUE,
                expires_at DATETIME NOT NULL,
                consumed_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(100) NOT NULL,
                event_context JSON NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
    }
}

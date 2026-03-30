<?php

declare(strict_types=1);

namespace App\Install;

use PDO;

final class AdminBootstrap
{
    public function prepare(PDO $pdo, int $ttlMinutes = 30): string
    {
        $token = bin2hex(random_bytes(32));
        $createdAt = gmdate('Y-m-d H:i:s');
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

        $stmt = $pdo->prepare(
            'INSERT INTO install_bootstrap (setup_token, expires_at, consumed_at, created_at)
             VALUES (:token, :expires_at, NULL, :created_at)'
        );

        $stmt->execute([
            ':token' => hash('sha256', $token),
            ':expires_at' => $expiresAt,
            ':created_at' => $createdAt,
        ]);

        return $token;
    }
}

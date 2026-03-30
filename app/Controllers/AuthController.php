<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;

final class AuthController
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function bootstrapAdmin(): void
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $status = 'missing';
        $message = 'No bootstrap token was supplied.';

        if ($token !== '') {
            try {
                $pdo = Connection::make($this->basePath);
                $stmt = $pdo->prepare('SELECT id, expires_at, consumed_at FROM install_bootstrap WHERE setup_token = :token LIMIT 1');
                $stmt->execute([':token' => hash('sha256', $token)]);
                $row = $stmt->fetch();

                if (!$row) {
                    $status = 'invalid';
                    $message = 'That bootstrap token is not valid.';
                } elseif (!empty($row['consumed_at'])) {
                    $status = 'consumed';
                    $message = 'That bootstrap token has already been consumed.';
                } elseif (strtotime((string) $row['expires_at']) < time()) {
                    $status = 'expired';
                    $message = 'That bootstrap token has expired.';
                } else {
                    $status = 'ready';
                    $message = 'Bootstrap token is valid. Discord admin claim should use this token during Phase 3 OAuth handoff.';
                }
            } catch (\Throwable $e) {
                $status = 'error';
                $message = $e->getMessage();
            }
        }

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin Bootstrap</title><style>body{font-family:Arial,sans-serif;background:#111827;color:#f9fafb;padding:40px} .card{max-width:900px;margin:0 auto;background:#1f2937;border:1px solid #374151;border-radius:16px;padding:24px} .status{font-weight:bold;text-transform:uppercase;color:#fbbf24} code{background:#111827;padding:2px 6px;border-radius:6px}</style></head><body><div class="card"><h1>First Admin Bootstrap</h1><p class="status">Status: ' . e($status) . '</p><p>' . e($message) . '</p><p>This endpoint is intentionally a prep/handoff endpoint for the upcoming Discord-authenticated admin claim flow.</p><p><a href="/">Return home</a></p></div></body></html>';
    }
}

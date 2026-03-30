<?php

declare(strict_types=1);

namespace App\Install;

use PDO;

final class SettingsSeeder
{
    public function seed(PDO $pdo, array $data): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $settings = [
            'app.name' => $data['app_name'] ?? 'RS3 Clan Discord Ranking App',
            'app.url' => rtrim((string) ($data['app_url'] ?? ''), '/'),
            'app.timezone' => $data['app_timezone'] ?? 'Australia/Sydney',
            'app.env' => $data['app_env'] ?? 'production',
            'discord.guild_id' => $data['discord_guild_id'] ?? '',
            'auth.provider' => 'discord',
            'install.completed' => '1',
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO settings (`key`, `value`, created_at, updated_at)
             VALUES (:key, :value, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)'
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([
                ':key' => $key,
                ':value' => (string) $value,
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Database;

final class SettingsRepository
{
    public function __construct(private readonly Database $db) {}

    public function all(): array
    {
        $rows = $this->db->fetchAll('SELECT setting_key, setting_value FROM app_settings ORDER BY setting_key');
        $settings = [];

        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->db->fetchOne(
            'SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1',
            ['key' => $key]
        );

        return $row['setting_value'] ?? $default;
    }

    public function set(string $key, ?string $value): void
    {
        $this->db->execute(
            'INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
            [
                'key' => $key,
                'value' => $value,
            ]
        );
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }
}

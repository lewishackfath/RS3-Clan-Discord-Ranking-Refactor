<?php

declare(strict_types=1);

namespace App\Install;

final class ConfigWriter
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function write(array $data): string
    {
        $path = $this->basePath . '/config/config.php';

        $config = [
            'app' => [
                'name' => $data['app_name'] ?? 'RS3 Clan Discord Ranking App',
                'url' => rtrim((string) ($data['app_url'] ?? ''), '/'),
                'env' => $data['app_env'] ?? 'production',
                'debug' => !empty($data['app_debug']),
                'timezone' => $data['app_timezone'] ?? 'Australia/Sydney',
            ],
            'db' => [
                'host' => $data['db_host'],
                'port' => (int) $data['db_port'],
                'database' => $data['db_name'],
                'username' => $data['db_user'],
                'password' => $data['db_pass'],
                'charset' => $data['db_charset'] ?? 'utf8mb4',
            ],
            'discord' => [
                'client_id' => $data['discord_client_id'] ?? '',
                'client_secret' => $data['discord_client_secret'] ?? '',
                'redirect_uri' => $data['discord_redirect_uri'] ?? '',
                'bot_token' => $data['discord_bot_token'] ?? '',
                'guild_id' => $data['discord_guild_id'] ?? '',
            ],
        ];

        $export = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";

        if (@file_put_contents($path, $export, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write config file: ' . $path);
        }

        return $path;
    }
}

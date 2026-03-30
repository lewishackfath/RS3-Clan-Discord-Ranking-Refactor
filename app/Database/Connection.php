<?php

declare(strict_types=1);

namespace App\Database;

use App\Config;
use PDO;

final class Connection
{
    public static function make(string $basePath): PDO
    {
        $config = Config::load($basePath);
        if (!isset($config['db']) || !is_array($config['db'])) {
            throw new \RuntimeException('Database config is missing.');
        }

        return self::makeFromArray($config['db']);
    }

    public static function makeFromArray(array $input): PDO
    {
        $host = trim((string) ($input['host'] ?? 'localhost'));
        $port = (int) ($input['port'] ?? 3306);
        $name = trim((string) ($input['database'] ?? ''));
        $user = trim((string) ($input['username'] ?? ''));
        $pass = (string) ($input['password'] ?? '');
        $charset = trim((string) ($input['charset'] ?? 'utf8mb4'));

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function test(array $input): array
    {
        try {
            $pdo = self::makeFromArray($input);
            $pdo->query('SELECT 1');

            return ['ok' => true, 'message' => 'Database connection successful.'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}

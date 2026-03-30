<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Connection;
use App\Database\Schema;
use App\Install\AdminBootstrap;
use App\Install\ConfigWriter;
use App\Install\InstallState;
use App\Install\RequirementChecker;
use App\Install\SettingsSeeder;
use PDO;

final class InstallController
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function index(): void
    {
        $state = new InstallState($this->basePath);
        if ($state->isInstalled()) {
            redirect('/');
        }

        $checker = new RequirementChecker($this->basePath);
        $requirements = $checker->check();
        $errors = session_get_flash('errors', []);
        $success = session_get_flash('success');

        require $this->basePath . '/resources/views/install/index.php';
        session_clear_old_input();
    }

    public function store(): void
    {
        $state = new InstallState($this->basePath);
        if ($state->isInstalled()) {
            http_response_code(403);
            echo 'Application is already installed.';
            return;
        }

        if (!verify_csrf($_POST['_csrf'] ?? null)) {
            http_response_code(419);
            echo 'Invalid CSRF token.';
            return;
        }

        $data = $this->validatedInput();
        session_old_input($data);

        $checker = new RequirementChecker($this->basePath);
        $requirements = $checker->check();

        $errors = $this->validate($data, $requirements['ok']);
        if ($errors !== []) {
            session_flash('errors', $errors);
            redirect('/install');
        }

        $test = Connection::test([
            'host' => $data['db_host'],
            'port' => $data['db_port'],
            'database' => $data['db_name'],
            'username' => $data['db_user'],
            'password' => $data['db_pass'],
            'charset' => $data['db_charset'],
        ]);

        if (!$test['ok']) {
            session_flash('errors', ['db' => 'Database connection failed: ' . $test['message']]);
            redirect('/install');
        }

        $pdo = null;

        try {
            $pdo = Connection::makeFromArray([
                'host' => $data['db_host'],
                'port' => $data['db_port'],
                'database' => $data['db_name'],
                'username' => $data['db_user'],
                'password' => $data['db_pass'],
                'charset' => $data['db_charset'],
            ]);

            $pdo->beginTransaction();

            (new Schema())->create($pdo);
            (new SettingsSeeder())->seed($pdo, $data);
            $bootstrapToken = (new AdminBootstrap())->prepare($pdo, 60);

            $pdo->commit();

            (new ConfigWriter($this->basePath))->write($data);
            $state->writeLock([
                'app_name' => $data['app_name'],
                'app_url' => $data['app_url'],
                'db_name' => $data['db_name'],
            ]);

            session_flash('success', 'Installation completed successfully.');
            redirect('/install/success?bootstrap=' . urlencode($bootstrapToken));
        } catch (\Throwable $e) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            session_flash('errors', [
                'install' => 'Install failed: ' . $e->getMessage(),
            ]);
            redirect('/install');
        }
    }

    public function success(): void
    {
        $state = new InstallState($this->basePath);
        if (!$state->isInstalled()) {
            redirect('/install');
        }

        $bootstrapToken = trim((string) ($_GET['bootstrap'] ?? ''));
        $success = session_get_flash('success', 'Installation completed successfully.');
        require $this->basePath . '/resources/views/install/success.php';
    }

    private function validatedInput(): array
    {
        return [
            'app_name' => trim((string) ($_POST['app_name'] ?? 'RS3 Clan Discord Ranking App')),
            'app_url' => trim((string) ($_POST['app_url'] ?? '')),
            'app_env' => trim((string) ($_POST['app_env'] ?? 'production')),
            'app_debug' => isset($_POST['app_debug']) ? '1' : '0',
            'app_timezone' => trim((string) ($_POST['app_timezone'] ?? 'Australia/Sydney')),
            'db_host' => trim((string) ($_POST['db_host'] ?? 'localhost')),
            'db_port' => trim((string) ($_POST['db_port'] ?? '3306')),
            'db_name' => trim((string) ($_POST['db_name'] ?? '')),
            'db_user' => trim((string) ($_POST['db_user'] ?? '')),
            'db_pass' => (string) ($_POST['db_pass'] ?? ''),
            'db_charset' => trim((string) ($_POST['db_charset'] ?? 'utf8mb4')),
            'discord_client_id' => trim((string) ($_POST['discord_client_id'] ?? '')),
            'discord_client_secret' => trim((string) ($_POST['discord_client_secret'] ?? '')),
            'discord_redirect_uri' => trim((string) ($_POST['discord_redirect_uri'] ?? '')),
            'discord_bot_token' => trim((string) ($_POST['discord_bot_token'] ?? '')),
            'discord_guild_id' => trim((string) ($_POST['discord_guild_id'] ?? '')),
        ];
    }

    private function validate(array $data, bool $requirementsOk): array
    {
        $errors = [];

        if (!$requirementsOk) {
            $errors['requirements'] = 'One or more server requirements failed.';
        }

        foreach (['app_name', 'app_url', 'db_host', 'db_port', 'db_name', 'db_user', 'discord_client_id', 'discord_client_secret', 'discord_redirect_uri', 'discord_bot_token'] as $required) {
            if ($data[$required] === '') {
                $errors[$required] = 'This field is required.';
            }
        }

        if ($data['app_url'] !== '' && !filter_var($data['app_url'], FILTER_VALIDATE_URL)) {
            $errors['app_url'] = 'App URL must be a valid URL.';
        }

        if ($data['discord_redirect_uri'] !== '' && !filter_var($data['discord_redirect_uri'], FILTER_VALIDATE_URL)) {
            $errors['discord_redirect_uri'] = 'Discord redirect URI must be a valid URL.';
        }

        if (!ctype_digit($data['db_port'])) {
            $errors['db_port'] = 'Database port must be numeric.';
        }

        return $errors;
    }
}

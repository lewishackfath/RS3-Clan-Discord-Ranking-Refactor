<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install Application</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; font-family: Arial, sans-serif; background: #0f172a; color: #e5e7eb; }
        .wrap { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
        .card { background: #111827; border: 1px solid #374151; border-radius: 18px; padding: 24px; box-shadow: 0 20px 50px rgba(0,0,0,.35); }
        h1, h2, h3 { margin-top: 0; }
        .grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 24px; }
        .stack > * + * { margin-top: 20px; }
        .field { margin-bottom: 14px; }
        label { display: block; font-size: 14px; margin-bottom: 6px; color: #cbd5e1; }
        input, select { width: 100%; box-sizing: border-box; padding: 12px 14px; border-radius: 12px; border: 1px solid #475569; background: #0f172a; color: #f8fafc; }
        input[type="checkbox"] { width: auto; }
        .checkbox { display: flex; align-items: center; gap: 8px; }
        .btn { display: inline-block; border: 0; border-radius: 12px; background: #2563eb; color: white; padding: 12px 18px; font-weight: 700; cursor: pointer; }
        .muted { color: #94a3b8; font-size: 14px; }
        .error { background: #3f1d1d; border: 1px solid #7f1d1d; color: #fecaca; border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; }
        .success { background: #132e20; border: 1px solid #166534; color: #bbf7d0; border-radius: 12px; padding: 12px 14px; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; border-bottom: 1px solid #374151; text-align: left; font-size: 14px; }
        .ok { color: #86efac; font-weight: 700; }
        .fail { color: #fca5a5; font-weight: 700; }
        .section-title { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #374151; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="grid">
        <div class="card stack">
            <div>
                <h1>First-Run Setup Wizard</h1>
                <p class="muted">This installer performs requirements checks, database connection testing, schema creation, config writing, install lock creation, initial settings seed, and first admin bootstrap preparation.</p>
            </div>

            <?php if ($success): ?>
                <div class="success"><?= e((string) $success) ?></div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error">
                    <strong>Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?= e((string) $error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/install">
                <?= csrf_field() ?>

                <div>
                    <h2 class="section-title">Application</h2>
                    <div class="field">
                        <label for="app_name">App Name</label>
                        <input id="app_name" name="app_name" value="<?= e(old('app_name', 'RS3 Clan Discord Ranking App')) ?>" required>
                    </div>
                    <div class="field">
                        <label for="app_url">App URL</label>
                        <input id="app_url" name="app_url" value="<?= e(old('app_url', 'https://your-domain.example.com')) ?>" required>
                    </div>
                    <div class="field">
                        <label for="app_env">Environment</label>
                        <select id="app_env" name="app_env">
                            <?php $env = old('app_env', 'production'); ?>
                            <option value="production" <?= $env === 'production' ? 'selected' : '' ?>>production</option>
                            <option value="staging" <?= $env === 'staging' ? 'selected' : '' ?>>staging</option>
                            <option value="local" <?= $env === 'local' ? 'selected' : '' ?>>local</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="app_timezone">Timezone</label>
                        <input id="app_timezone" name="app_timezone" value="<?= e(old('app_timezone', 'Australia/Sydney')) ?>">
                    </div>
                    <div class="field checkbox">
                        <input id="app_debug" type="checkbox" name="app_debug" value="1" <?= old('app_debug') === '1' ? 'checked' : '' ?>>
                        <label for="app_debug" style="margin:0;">Enable debug mode</label>
                    </div>
                </div>

                <div>
                    <h2 class="section-title">Database</h2>
                    <div class="field"><label for="db_host">Host</label><input id="db_host" name="db_host" value="<?= e(old('db_host', 'localhost')) ?>" required></div>
                    <div class="field"><label for="db_port">Port</label><input id="db_port" name="db_port" value="<?= e(old('db_port', '3306')) ?>" required></div>
                    <div class="field"><label for="db_name">Database Name</label><input id="db_name" name="db_name" value="<?= e(old('db_name')) ?>" required></div>
                    <div class="field"><label for="db_user">Username</label><input id="db_user" name="db_user" value="<?= e(old('db_user')) ?>" required></div>
                    <div class="field"><label for="db_pass">Password</label><input id="db_pass" type="password" name="db_pass" value="<?= e(old('db_pass')) ?>"></div>
                    <div class="field"><label for="db_charset">Charset</label><input id="db_charset" name="db_charset" value="<?= e(old('db_charset', 'utf8mb4')) ?>"></div>
                </div>

                <div>
                    <h2 class="section-title">Discord</h2>
                    <div class="field"><label for="discord_client_id">Client ID</label><input id="discord_client_id" name="discord_client_id" value="<?= e(old('discord_client_id')) ?>" required></div>
                    <div class="field"><label for="discord_client_secret">Client Secret</label><input id="discord_client_secret" name="discord_client_secret" value="<?= e(old('discord_client_secret')) ?>" required></div>
                    <div class="field"><label for="discord_redirect_uri">Redirect URI</label><input id="discord_redirect_uri" name="discord_redirect_uri" value="<?= e(old('discord_redirect_uri')) ?>" required></div>
                    <div class="field"><label for="discord_bot_token">Bot Token</label><input id="discord_bot_token" name="discord_bot_token" value="<?= e(old('discord_bot_token')) ?>" required></div>
                    <div class="field"><label for="discord_guild_id">Guild ID</label><input id="discord_guild_id" name="discord_guild_id" value="<?= e(old('discord_guild_id')) ?>"></div>
                </div>

                <button class="btn" type="submit">Run Installer</button>
            </form>
        </div>

        <div class="card stack">
            <div>
                <h2>Requirements Check</h2>
                <p class="muted">These checks must pass before the installer can continue.</p>
            </div>
            <table>
                <thead>
                <tr>
                    <th>Requirement</th>
                    <th>Status</th>
                    <th>Meta</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($requirements['checks'] as $check): ?>
                    <tr>
                        <td><?= e($check['label']) ?></td>
                        <td class="<?= $check['ok'] ? 'ok' : 'fail' ?>"><?= $check['ok'] ? 'PASS' : 'FAIL' ?></td>
                        <td><?= e((string) $check['meta']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div>
                <h3>What happens on install</h3>
                <ul>
                    <li>Database credentials are tested first.</li>
                    <li>Schema is created inside a transaction.</li>
                    <li>Initial settings are seeded.</li>
                    <li>A one-time first-admin bootstrap token is generated.</li>
                    <li><code>config/config.php</code> is written.</li>
                    <li><code>storage/install/installed.lock</code> is created.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
</body>
</html>

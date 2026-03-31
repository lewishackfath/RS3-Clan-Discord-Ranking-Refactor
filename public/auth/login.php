<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';

if (current_admin()) {
    redirect('/admin/index.php');
}

$state = bin2hex(random_bytes(24));
$_SESSION['oauth_state'] = $state;
$flashes = get_flashes();

$query = http_build_query([
    'client_id' => env('DISCORD_CLIENT_ID', ''),
    'redirect_uri' => env('DISCORD_REDIRECT_URI', ''),
    'response_type' => 'code',
    'scope' => 'identify',
    'state' => $state,
    'prompt' => 'consent',
]);
$loginUrl = 'https://discord.com/api/oauth2/authorize?' . $query;
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RS3 Clan Discord Ranker</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { width: min(620px, calc(100vw - 32px)); background: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 28px; }
        .brand-logo { display:block; max-width: 220px; width: 100%; height: auto; margin-bottom: 18px; }
        .brand-clan { display:flex; align-items:center; gap:12px; margin-bottom:18px; }
        .brand-clan-logo { width:52px; height:52px; border-radius:14px; object-fit:cover; border:1px solid #334155; background:#0b1220; flex:0 0 auto; }
        .btn { display: inline-block; background: #5865F2; color: white; padding: 12px 16px; border-radius: 10px; text-decoration: none; }
        p, li { color: #cbd5e1; }
        code { color: #93c5fd; }
        .flash { padding: 12px 14px; border-radius: 10px; margin: 0 0 12px 0; }
        .flash.success { background: #052e16; border: 1px solid #166534; }
        .flash.error { background: #450a0a; border: 1px solid #991b1b; }
        .flash.info { background: #082f49; border: 1px solid #0369a1; }
    </style>
</head>
<body>
    <div class="card">
        <?php $clanLogoUrl = trim((string)env('CLAN_LOGO_URL', '')); ?>
        <img class="brand-logo" src="/assets/logo.png" alt="HIT Media">
        <div class="brand-clan">
            <?php if ($clanLogoUrl !== ''): ?>
                <img class="brand-clan-logo" src="<?= h($clanLogoUrl) ?>" alt="Clan logo">
            <?php endif; ?>
            <div>
                <h1 style="margin:0 0 6px 0;"><?= h(env('APP_NAME', 'RS3 Clan Discord Ranker')) ?></h1>
                <p style="margin:0; color:#94a3b8;">Sign in with Discord</p>
            </div>
        </div>

        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>

        <p>This application uses Discord OAuth for admin access and the bot token directly for guild, role, and member API actions.</p>
        <ul>
            <li>OAuth handles admin sign-in.</li>
            <li>The web app uses the bot token directly for guild, role and member API calls.</li>
            <li>The bot still needs to be installed in the server and placed at the top of the role list.</li>
        </ul>
        <p><a class="btn" href="<?= h($loginUrl) ?>">Continue with Discord</a></p>
    </div>
</body>
</html>

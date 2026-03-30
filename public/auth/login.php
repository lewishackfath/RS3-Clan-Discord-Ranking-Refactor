<?php
declare(strict_types=1);
require_once __DIR__ . '/../../app/config/bootstrap.php';

$state = bin2hex(random_bytes(24));
$_SESSION['oauth_state'] = $state;
$query = http_build_query([
    'client_id' => env('DISCORD_CLIENT_ID', ''),
    'redirect_uri' => env('DISCORD_REDIRECT_URI', ''),
    'response_type' => 'code',
    'scope' => 'identify guilds',
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
    <title>Discord Login</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { width: min(560px, calc(100vw - 32px)); background: #111827; border: 1px solid #1f2937; border-radius: 16px; padding: 28px; }
        .btn { display: inline-block; background: #5865F2; color: white; padding: 12px 16px; border-radius: 10px; text-decoration: none; }
        p { color: #cbd5e1; }
        code { color: #93c5fd; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Sign in with Discord</h1>
        <p>This Phase 1 admin interface uses Discord OAuth for administrator login.</p>
        <p>Before signing in, make sure your <code>.env</code> values are set correctly, especially the OAuth client ID, secret and redirect URI.</p>
        <p><a class="btn" href="<?= h($loginUrl) ?>">Continue with Discord</a></p>
    </div>
</body>
</html>

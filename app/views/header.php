<?php
$flashes = get_flashes();
$admin = current_admin();
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(env('APP_NAME', 'RS3 Clan Discord Ranker')) ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; background: #0f172a; color: #e2e8f0; }
        a { color: #93c5fd; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        .sidebar { background: #111827; padding: 20px; border-right: 1px solid #1f2937; }
        .sidebar h1 { font-size: 18px; margin-top: 0; }
        .sidebar nav a { display: block; padding: 10px 12px; border-radius: 8px; margin-bottom: 6px; color: #cbd5e1; }
        .sidebar nav a.active, .sidebar nav a:hover { background: #1e293b; color: #fff; }
        .content { padding: 24px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card { background: #111827; border: 1px solid #1f2937; border-radius: 12px; padding: 18px; margin-bottom: 18px; }
        .flash { padding: 12px 14px; border-radius: 10px; margin-bottom: 12px; }
        .flash.success { background: #052e16; border: 1px solid #166534; }
        .flash.error { background: #450a0a; border: 1px solid #991b1b; }
        .flash.info { background: #082f49; border: 1px solid #0369a1; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 10px; border-bottom: 1px solid #1f2937; vertical-align: top; }
        th { color: #cbd5e1; font-weight: 600; }
        input, select, button { background: #0f172a; color: #e2e8f0; border: 1px solid #334155; border-radius: 8px; padding: 8px 10px; }
        button { cursor: pointer; }
        .btn-primary { background: #1d4ed8; border-color: #1d4ed8; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 999px; font-size: 12px; }
        .badge.ok { background: #14532d; }
        .badge.warn { background: #78350f; }
        .badge.bad { background: #7f1d1d; }
        .grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .muted { color: #94a3b8; }
        .small { font-size: 12px; }
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { border-right: 0; border-bottom: 1px solid #1f2937; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h1><?= h(env('APP_NAME', 'RS3 Clan Discord Ranker')) ?></h1>
        <p class="muted small">Clan ID: <?= h(env('CLAN_ID', '1')) ?><br>Guild ID: <?= h(env('DISCORD_GUILD_ID', '')) ?></p>
        <nav>
            <a class="<?= str_contains($currentPath, '/admin/index.php') ? 'active' : '' ?>" href="/admin/index.php">Dashboard</a>
            <a class="<?= str_contains($currentPath, '/admin/role-mappings.php') ? 'active' : '' ?>" href="/admin/role-mappings.php">Role Mappings</a>
            <a class="<?= str_contains($currentPath, '/admin/roles.php') ? 'active' : '' ?>" href="/admin/roles.php">Role Flags</a>
            <a class="<?= str_contains($currentPath, '/admin/user-mappings.php') ? 'active' : '' ?>" href="/admin/user-mappings.php">User Mappings</a>
        </nav>
    </aside>
    <main class="content">
        <div class="topbar">
            <div>
                <strong>Admin Interface</strong>
            </div>
            <div>
                <?php if ($admin): ?>
                    Signed in as <strong><?= h($admin['global_name'] ?? $admin['username'] ?? 'Unknown') ?></strong>
                    · <a href="/auth/logout.php">Log out</a>
                <?php endif; ?>
            </div>
        </div>
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>

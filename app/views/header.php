<?php
declare(strict_types=1);
$admin = current_admin();
$flashes = get_flashes();
$path = $_SERVER['REQUEST_URI'] ?? '';
function nav_active(string $needle, string $path): string { return str_contains($path, $needle) ? 'active' : ''; }
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h(env('APP_NAME', 'RS3 Clan Ranker')) ?></title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f172a;
            --panel: #111827;
            --panel-2: #1f2937;
            --text: #e5e7eb;
            --muted: #94a3b8;
            --line: #243041;
            --primary: #5865f2;
            --green: #16a34a;
            --red: #dc2626;
            --amber: #d97706;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, sans-serif; background: var(--bg); color: var(--text); }
        a { color: #c7d2fe; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        .sidebar { background: #0b1220; border-right: 1px solid var(--line); padding: 24px 18px; }
        .brand { font-size: 20px; font-weight: 700; margin-bottom: 6px; }
        .muted { color: var(--muted); }
        .nav { display: grid; gap: 8px; margin-top: 24px; }
        .nav a { display: block; padding: 10px 12px; border-radius: 10px; color: var(--text); }
        .nav a.active, .nav a:hover { background: var(--panel-2); text-decoration: none; }
        .main { padding: 24px; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 16px; padding: 18px; margin-bottom: 18px; }
        .grid { display: grid; gap: 18px; }
        .grid.two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .flash { padding: 12px 14px; border-radius: 12px; margin-bottom: 12px; }
        .flash.success { background: #052e16; border: 1px solid #166534; }
        .flash.error { background: #450a0a; border: 1px solid #991b1b; }
        .flash.info { background: #082f49; border: 1px solid #0369a1; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: #cbd5e1; font-size: 14px; }
        .small { font-size: 12px; }
        .status { display: inline-block; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .status.ok { background: rgba(22,163,74,.15); color: #86efac; }
        .status.warn { background: rgba(217,119,6,.15); color: #fdba74; }
        .status.bad { background: rgba(220,38,38,.15); color: #fca5a5; }
        .btn, button, select, input[type=text], textarea { font: inherit; }
        .btn-primary, .btn-secondary { display: inline-block; border: 0; border-radius: 10px; padding: 10px 14px; cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--panel-2); color: var(--text); }
        input[type=text], select, textarea { width: 100%; border-radius: 10px; border: 1px solid var(--line); background: #0b1220; color: var(--text); padding: 10px 12px; }
        textarea { min-height: 140px; resize: vertical; }
        .inline { display: flex; gap: 10px; align-items: center; }
        .avatar { width: 36px; height: 36px; border-radius: 999px; vertical-align: middle; margin-right: 10px; }
        @media (max-width: 980px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { border-right: 0; border-bottom: 1px solid var(--line); }
            .grid.two { grid-template-columns: 1fr; }
            .main { padding: 16px; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand"><?= h(env('APP_NAME', 'RS3 Clan Ranker')) ?></div>
        <div class="muted small">Phase 1 • PHP-only admin</div>
        <nav class="nav">
            <a class="<?= nav_active('/admin/index.php', $path) ?>" href="/admin/index.php">Dashboard</a>
            <a class="<?= nav_active('/admin/clan-members.php', $path) ?>" href="/admin/clan-members.php">Clan Members</a>
            <a class="<?= nav_active('/admin/role-mappings.php', $path) ?>" href="/admin/role-mappings.php">Role Mappings</a>
            <a class="<?= nav_active('/admin/roles.php', $path) ?>" href="/admin/roles.php">Role Flags</a>
            <a class="<?= nav_active('/admin/user-mappings.php', $path) ?>" href="/admin/user-mappings.php">User Mappings</a>
        </nav>
    </aside>
    <main class="main">
        <div class="topbar">
            <div>
                <?php if ($admin): ?>
                    <strong><?= h($admin['username'] ?? 'Admin') ?></strong>
                    <span class="muted small">Discord ID: <?= h($admin['id'] ?? '') ?></span>
                <?php endif; ?>
            </div>
            <div><a class="btn-secondary" href="/auth/logout.php">Log out</a></div>
        </div>
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>

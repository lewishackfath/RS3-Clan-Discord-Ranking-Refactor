<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Installation Complete</title>
    <style>
        :root { color-scheme: dark; }
        body { margin: 0; font-family: Arial, sans-serif; background: #0f172a; color: #e5e7eb; }
        .wrap { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        .card { background: #111827; border: 1px solid #374151; border-radius: 18px; padding: 24px; }
        .success { background: #132e20; border: 1px solid #166534; color: #bbf7d0; border-radius: 12px; padding: 12px 14px; }
        .muted { color: #94a3b8; }
        .btn { display: inline-block; text-decoration: none; border-radius: 12px; background: #2563eb; color: white; padding: 12px 18px; font-weight: 700; }
        code { background: #0f172a; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Installation Complete</h1>
        <div class="success"><?= e((string) $success) ?></div>
        <p>The installer has written the config file, created the schema, seeded initial settings, generated the install lock, and prepared the first-admin bootstrap token.</p>

        <?php if ($bootstrapToken !== ''): ?>
            <p><strong>Bootstrap link:</strong></p>
            <p><code><?= e('/install/admin-bootstrap?token=' . $bootstrapToken) ?></code></p>
            <p><a class="btn" href="<?= e('/install/admin-bootstrap?token=' . $bootstrapToken) ?>">Open bootstrap handoff</a></p>
        <?php else: ?>
            <p class="muted">No bootstrap token was supplied to the success view.</p>
        <?php endif; ?>

        <p class="muted">Phase 3 should consume this token during Discord OAuth callback and create the first <code>super_admin</code> record before marking the token as consumed.</p>
        <p><a href="/">Go to application home</a></p>
    </div>
</div>
</body>
</html>

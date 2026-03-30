<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'App') ?></title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="site-header">
        <div class="container">
            <h1><?= e($title ?? 'App') ?></h1>
        </div>
    </header>

    <main class="container">
        <?= $content ?? '' ?>
    </main>
</body>
</html>

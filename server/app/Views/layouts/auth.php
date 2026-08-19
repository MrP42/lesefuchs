<?php /** @var string $content */ ?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title ?? 'Lesefuchs') ?> · Lesefuchs</title>
    <link rel="icon" href="data:image/svg+xml,<text y='0.9em' font-size='90'>🦊</text>">
    <link rel="stylesheet" href="/assets/css/app.css?v=<?= @filemtime(base_path('public/assets/css/app.css')) ?: 1 ?>">
</head>
<body class="auth-body">
<main class="auth-card">
    <?php if ($msg = \App\Core\Session::getFlash('error')): ?>
        <div class="flash flash-error"><?= e((string) $msg) ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
</body>
</html>

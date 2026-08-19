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
<body>
<header class="topbar">
    <a class="brand" href="/">🦊 <strong>Lesefuchs</strong> <span class="brand-sub">Familien-Server</span></a>
    <nav>
        <?php
        $nav = [
            '/'            => 'Übersicht',
            '/kinder'      => 'Kinder',
            '/bibliothek'  => 'Bibliothek',
            '/zuweisungen' => 'Zuweisungen',
            '/geraete'     => 'Geräte',
        ];
        if (\App\Core\Auth::isAdmin()) {
            $nav['/familie'] = 'Familie & Konten';
        }
        $current = \App\Core\Request::pathFromServer();
        foreach ($nav as $href => $label):
            $active = $href === '/' ? $current === '/' : str_starts_with($current, $href);
        ?>
            <a href="<?= e($href) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <form method="post" action="/logout" class="logout-form">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn-ghost" title="Abmelden"><?= e(\App\Core\Auth::user()['name'] ?? '') ?> · Abmelden</button>
    </form>
</header>
<main class="container">
    <?php if ($msg = \App\Core\Session::getFlash('success')): ?>
        <div class="flash flash-success"><?= e((string) $msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = \App\Core\Session::getFlash('error')): ?>
        <div class="flash flash-error"><?= e((string) $msg) ?></div>
    <?php endif; ?>
    <?= $content ?>
</main>
</body>
</html>

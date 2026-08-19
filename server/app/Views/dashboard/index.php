<h1>Übersicht</h1>

<div class="stat-row">
    <div class="stat"><span class="stat-value"><?= count($children) ?></span><span class="stat-label">Kinder</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $counts['packages'] ?></span><span class="stat-label">Pakete bereit</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $counts['devices'] ?></span><span class="stat-label">Geräte</span></div>
    <div class="stat"><span class="stat-value"><?= (int) $counts['events'] ?></span><span class="stat-label">Ereignisse</span></div>
</div>

<?php if ($children === []): ?>
    <div class="card empty">
        <p>Noch keine Kinder angelegt. <a href="/kinder/neu">Erstes Kind anlegen</a> — danach in der
        <a href="/bibliothek">Bibliothek</a> ein Paket hochladen und <a href="/zuweisungen">zuweisen</a>.</p>
    </div>
<?php else: ?>
    <div class="card-grid">
    <?php foreach ($children as $child): ?>
        <div class="card child-card" style="--child-color: <?= e($child['color_hex']) ?>">
            <div class="child-head">
                <span class="avatar"><?= e(\App\Controllers\ChildrenController::AVATARS[$child['avatar_key']] ?? '🦊') ?></span>
                <div>
                    <strong><?= e($child['name']) ?></strong>
                    <div class="muted">Lesestufe <?= (int) $child['reading_level'] ?></div>
                </div>
            </div>
            <dl class="kv">
                <dt>Hörzeit (7 Tage)</dt>
                <dd><?= round($child['week_ms'] / 60000) ?> min</dd>
                <dt>Abgeschlossen</dt>
                <dd><?= (int) $child['finished'] ?> Bücher</dd>
                <dt>Aktuell</dt>
                <dd><?= $child['current'] ? e($child['current']['title']) : '—' ?></dd>
            </dl>
            <a class="btn btn-ghost" href="/kinder/<?= (int) $child['id'] ?>">Profil bearbeiten</a>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

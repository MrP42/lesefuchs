<div class="page-head">
    <h1>Bibliothek</h1>
    <form method="post" action="/bibliothek/upload" enctype="multipart/form-data" class="upload-form">
        <?= \App\Core\Csrf::field() ?>
        <input type="file" name="paket" accept=".lesepaket,.zip" required>
        <button type="submit" class="btn btn-primary">Paket hochladen</button>
    </form>
</div>

<?php if ($packages === []): ?>
    <div class="card empty">
        <p>Noch keine Inhalte. Ein <code>.lesepaket</code> hochladen — vom Studio erzeugt oder von Hand gebaut
        (ZIP mit <code>manifest.json</code> + <code>content.json</code>).</p>
    </div>
<?php else: ?>
    <div class="card-grid">
    <?php foreach ($packages as $p): ?>
        <div class="card package-card <?= $p['status'] === 'archived' ? 'is-archived' : '' ?>">
            <div class="package-cover">
                <?php $hasCover = true; /* Cover wird über die Datei-Route geladen; onerror versteckt */ ?>
                <img src="/bibliothek/<?= (int) $p['id'] ?>/datei/cover.webp" alt="" loading="lazy"
                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                <div class="cover-fallback" style="display:none">📖</div>
            </div>
            <div class="package-body">
                <strong><?= e($p['title']) ?></strong>
                <div class="muted"><?= e($p['author'] ?? '') ?></div>
                <div class="badges">
                    <span class="badge"><?= e($p['type'] === 'FACSIMILE' ? 'Faksimile' : 'Fließtext') ?></span>
                    <span class="badge">Stufe <?= (int) $p['reading_level'] ?></span>
                    <span class="badge"><?= round(((int) $p['duration_ms']) / 60000) ?> min</span>
                    <span class="badge"><?= number_format(((int) $p['size_bytes']) / 1048576, 1, ',', '.') ?> MB</span>
                    <?php if ($p['status'] === 'archived'): ?><span class="badge badge-muted">archiviert</span><?php endif; ?>
                </div>
                <a class="btn btn-ghost" href="/bibliothek/<?= (int) $p['id'] ?>">Details</a>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>

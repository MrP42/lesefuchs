<div class="page-head">
    <h1><?= e($package['title']) ?></h1>
    <a class="btn btn-ghost" href="/bibliothek">Zurück</a>
</div>

<div class="card">
    <dl class="kv kv-wide">
        <dt>Autor</dt><dd><?= e($package['author'] ?? '—') ?></dd>
        <dt>Typ</dt><dd><?= e($package['type'] === 'FACSIMILE' ? 'Faksimile (Seitenbilder)' : 'Fließtext (Reflow)') ?></dd>
        <dt>Lesestufe</dt><dd><?= (int) $package['reading_level'] ?></dd>
        <dt>Seiten</dt><dd><?= (int) $package['page_count'] ?></dd>
        <dt>Dauer</dt><dd><?= round(((int) $package['duration_ms']) / 60000) ?> min</dd>
        <dt>Stimme</dt><dd><?= e($package['voice'] ?? '—') ?></dd>
        <dt>Paket-Version</dt><dd><?= (int) $package['package_version'] ?></dd>
        <dt>Größe</dt><dd><?= number_format(((int) $package['size_bytes']) / 1048576, 1, ',', '.') ?> MB</dd>
        <dt>Prüfsumme</dt><dd><code class="small"><?= e($package['checksum'] ?? '—') ?></code></dd>
        <dt>Manifest-ID</dt><dd><code class="small"><?= e($package['uuid']) ?></code></dd>
        <dt>Zugewiesen an</dt>
        <dd><?= $assigned !== [] ? e(implode(', ', array_column($assigned, 'name'))) : '— (<a href="/zuweisungen">zuweisen</a>)' ?></dd>
        <dt>Status</dt><dd><?= e($package['status'] === 'archived' ? 'archiviert' : 'bereit') ?></dd>
    </dl>
    <div class="form-actions">
        <form method="post" action="/bibliothek/<?= (int) $package['id'] ?>/archiv">
            <?= \App\Core\Csrf::field() ?>
            <button type="submit" class="btn btn-ghost"><?= $package['status'] === 'archived' ? 'Reaktivieren' : 'Archivieren' ?></button>
        </form>
        <form method="post" action="/bibliothek/<?= (int) $package['id'] ?>/loeschen"
              onsubmit="return confirm('Paket samt Dateien und Fortschritt unwiderruflich löschen?')">
            <?= \App\Core\Csrf::field() ?>
            <button type="submit" class="btn btn-danger">Löschen</button>
        </form>
    </div>
</div>

<h2>Dateien (<?= count($files) ?>)</h2>
<table class="table">
    <thead><tr><th>Pfad</th><th>Größe</th><th>SHA-256</th></tr></thead>
    <tbody>
    <?php foreach ($files as $f): ?>
        <tr>
            <td><a href="/bibliothek/<?= (int) $package['id'] ?>/datei/<?= e($f['rel_path']) ?>" target="_blank"><?= e($f['rel_path']) ?></a></td>
            <td><?= number_format(((int) $f['size_bytes']) / 1024, 1, ',', '.') ?> KB</td>
            <td><code class="small"><?= e(substr((string) $f['sha256'], 0, 16)) ?>…</code></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

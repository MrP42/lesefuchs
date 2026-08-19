<div class="page-head">
    <h1>Geräte</h1>
    <form method="post" action="/geraete/code">
        <?= \App\Core\Csrf::field() ?>
        <button type="submit" class="btn btn-primary">Pairing-Code erzeugen</button>
    </form>
</div>

<?php if ($activeCode !== null): ?>
    <div class="card pairing-card">
        <p class="muted">Diesen Code am Tablet unter „Mit Server verbinden" eingeben
            (gültig bis <?= e(date('H:i', strtotime((string) $activeCode['expires_at']))) ?> Uhr):</p>
        <div class="pairing-code"><?= e(implode(' ', str_split((string) $activeCode['code'], 3))) ?></div>
    </div>
<?php endif; ?>

<?php if ($devices === []): ?>
    <div class="card empty"><p>Noch kein Tablet gekoppelt. Code erzeugen und am Tablet eingeben.</p></div>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Name</th><th>Gekoppelt</th><th>Zuletzt gesehen</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <tr>
                <td>📱 <?= e($d['name']) ?></td>
                <td><?= e((string) $d['paired_at']) ?></td>
                <td><?= $d['last_seen_at'] !== null ? e((string) $d['last_seen_at']) : '—' ?></td>
                <td><?= $d['revoked_at'] !== null ? '<span class="badge badge-muted">abgemeldet</span>' : '<span class="badge badge-ok">aktiv</span>' ?></td>
                <td class="actions">
                    <?php if ($d['revoked_at'] === null): ?>
                    <form method="post" action="/geraete/<?= (int) $d['id'] ?>/abmelden"
                          onsubmit="return confirm('Gerät abmelden? Das Tablet muss danach neu gekoppelt werden.')">
                        <?= \App\Core\Csrf::field() ?>
                        <button type="submit" class="btn btn-ghost">Abmelden</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

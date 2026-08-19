<div class="page-head">
    <h1>Kinder</h1>
    <a class="btn btn-primary" href="/kinder/neu">+ Kind anlegen</a>
</div>

<?php if ($children === []): ?>
    <div class="card empty"><p>Noch keine Kinderprofile.</p></div>
<?php else: ?>
    <table class="table">
        <thead><tr><th>Name</th><th>Lesestufe</th><th>Jahrgang</th><th>Highlight-Modus</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($children as $child): ?>
            <tr>
                <td>
                    <span class="avatar-sm" style="background: <?= e($child['color_hex']) ?>22"><?= e(\App\Controllers\ChildrenController::AVATARS[$child['avatar_key']] ?? '🦊') ?></span>
                    <?= e($child['name']) ?>
                </td>
                <td><?= (int) $child['reading_level'] ?></td>
                <td><?= $child['birth_year'] !== null ? (int) $child['birth_year'] : '—' ?></td>
                <td><?= e(\App\Controllers\ChildrenController::HIGHLIGHT_MODES[$child['highlight_mode'] ?? 'WORD'] ?? '—') ?></td>
                <td class="actions"><a class="btn btn-ghost" href="/kinder/<?= (int) $child['id'] ?>">Bearbeiten</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

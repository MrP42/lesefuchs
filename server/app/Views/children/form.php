<?php
/** @var array|null $child  @var array|null $settings */
$isNew = $child === null;
$s = $settings ?? [];
$val = static fn (string $key, mixed $default = '') => e((string) ($s[$key] ?? $default));
$minutesToTime = static function ($m): string {
    if ($m === null || $m === '') { return ''; }
    return sprintf('%02d:%02d', intdiv((int) $m, 60), ((int) $m) % 60);
};
?>
<div class="page-head">
    <h1><?= $isNew ? 'Kind anlegen' : e($child['name']) ?></h1>
    <a class="btn btn-ghost" href="/kinder">Zurück</a>
</div>

<form method="post" action="<?= $isNew ? '/kinder' : '/kinder/' . (int) $child['id'] ?>" class="card form">
    <?= \App\Core\Csrf::field() ?>

    <h2>Profil</h2>
    <div class="form-grid">
        <label>Name
            <input type="text" name="name" value="<?= e($child['name'] ?? old('name')) ?>" required maxlength="100">
        </label>
        <label>Geburtsjahr
            <input type="number" name="birth_year" min="2005" max="2026" value="<?= e((string) ($child['birth_year'] ?? '')) ?>">
        </label>
        <label>Lesestufe
            <select name="reading_level">
                <?php foreach ([1 => '1 · Vorschule', 2 => '2 · 1./2. Klasse', 3 => '3 · 3./4. Klasse'] as $lvl => $label): ?>
                    <option value="<?= $lvl ?>" <?= (int) ($child['reading_level'] ?? 1) === $lvl ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Farbe
            <input type="color" name="color_hex" value="<?= e($child['color_hex'] ?? '#F59E0B') ?>">
        </label>
    </div>
    <div class="avatar-picker">
        <?php foreach (\App\Controllers\ChildrenController::AVATARS as $key => $emoji): ?>
            <label class="avatar-option">
                <input type="radio" name="avatar_key" value="<?= e($key) ?>" <?= ($child['avatar_key'] ?? 'fox') === $key ? 'checked' : '' ?>>
                <span><?= $emoji ?></span>
            </label>
        <?php endforeach; ?>
    </div>

    <h2>Vorlesen &amp; Anzeige</h2>
    <div class="form-grid">
        <label>Highlight-Modus
            <select name="highlight_mode">
                <?php foreach (\App\Controllers\ChildrenController::HIGHLIGHT_MODES as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= ($s['highlight_mode'] ?? 'WORD') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Sprechtempo (0,7–1,3)
            <input type="number" name="speech_rate" step="0.05" min="0.7" max="1.3" value="<?= $val('speech_rate', '1.0') ?>">
        </label>
        <label>Schriftgröße (1,0–1,8)
            <input type="number" name="font_scale" step="0.1" min="1.0" max="1.8" value="<?= $val('font_scale', '1.2') ?>">
        </label>
        <label>Schriftart
            <select name="font_family">
                <option value="andika" <?= ($s['font_family'] ?? 'andika') === 'andika' ? 'selected' : '' ?>>Andika (Leselern-Schrift)</option>
                <option value="opendyslexic" <?= ($s['font_family'] ?? '') === 'opendyslexic' ? 'selected' : '' ?>>OpenDyslexic</option>
            </select>
        </label>
        <label>Vorlauf Highlight (ms)
            <input type="number" name="lead_offset_ms" min="-300" max="300" value="<?= $val('lead_offset_ms', '-60') ?>">
        </label>
    </div>
    <label class="checkbox">
        <input type="checkbox" name="syllable_coloring" <?= !empty($s['syllable_coloring']) ? 'checked' : '' ?>>
        Silbenfärbung (blau/rot, Fibel-Methode)
    </label>
    <label class="checkbox">
        <input type="checkbox" name="scanner_enabled" <?= ($s === [] || !empty($s['scanner_enabled'])) ? 'checked' : '' ?>>
        Kamera-Scanner erlauben
    </label>

    <h2>Bildschirmzeit</h2>
    <div class="form-grid">
        <label>Tageslimit (Minuten, leer = unbegrenzt)
            <input type="number" name="daily_limit_minutes" min="5" max="600" value="<?= $val('daily_limit_minutes') ?>">
        </label>
        <label>Ruhezeit von
            <input type="time" name="quiet_hours_start" value="<?= e($minutesToTime($s['quiet_hours_start'] ?? null)) ?>">
        </label>
        <label>Ruhezeit bis
            <input type="time" name="quiet_hours_end" value="<?= e($minutesToTime($s['quiet_hours_end'] ?? null)) ?>">
        </label>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary"><?= $isNew ? 'Anlegen' : 'Speichern' ?></button>
    </div>
</form>

<?php if (!$isNew): ?>
<form method="post" action="/kinder/<?= (int) $child['id'] ?>/loeschen" class="danger-zone"
      onsubmit="return confirm('Kind, Zuweisungen und Fortschritt wirklich löschen?')">
    <?= \App\Core\Csrf::field() ?>
    <button type="submit" class="btn btn-danger">Kind löschen</button>
</form>
<?php endif; ?>

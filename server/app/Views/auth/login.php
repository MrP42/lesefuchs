<div style="text-align:center;margin-bottom:1.5rem">
    <div style="font-size:3rem">🦊</div>
    <h1>Lesefuchs</h1>
    <p class="muted">Eltern-Anmeldung</p>
</div>
<form method="post" action="/login">
    <?= \App\Core\Csrf::field() ?>
    <label>E-Mail
        <input type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username">
    </label>
    <label>Passwort
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit" class="btn btn-primary" style="width:100%">Anmelden</button>
</form>

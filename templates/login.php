<?php
/**
 * @var string      $email
 * @var string|null $error
 */
?>
<section class="card card--narrow">
    <h1>Anmelden</h1>

    <?php if (($error ?? null) !== null): ?>
        <p class="flash flash--error"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login" class="stack">
        <?= csrfField() ?>

        <label>
            E-Mail
            <input type="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
        </label>

        <label>
            Passwort
            <input type="password" name="password" autocomplete="current-password" required>
        </label>

        <button type="submit" class="btn btn-primary">Anmelden</button>
    </form>
</section>

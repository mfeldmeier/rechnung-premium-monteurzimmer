<?php
/**
 * @var string      $heading
 * @var string      $message
 * @var string|null $detail  nur ausserhalb von APP_ENV=prod gesetzt
 */
$detail ??= null;
?>
<section class="card">
    <h1><?= e($heading ?? 'Fehler') ?></h1>
    <p><?= e($message ?? 'Unbekannter Fehler.') ?></p>

    <?php if ($detail !== null): ?>
        <pre class="trace"><?= e($detail) ?></pre>
    <?php endif; ?>

    <p><a class="btn" href="/">Zur Übersicht</a></p>
</section>

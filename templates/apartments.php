<?php
/** @var array<int,array<string,mixed>> $rows */
?>
<div class="pagehead">
    <h1>Wohnungen</h1>
</div>

<section class="card">
    <?php foreach ($rows as $row): ?>
        <form method="post" action="/wohnungen" class="row inline-form<?= (int) $row['is_active'] === 1 ? '' : ' is-inactive' ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= e($row['id']) ?>">
            <label class="grow">Name
                <input type="text" name="name" value="<?= e($row['name']) ?>" maxlength="100" required>
            </label>
            <label class="narrow">Betten
                <input type="number" name="beds_default" value="<?= e($row['beds_default']) ?>" min="1" step="1">
            </label>
            <label class="narrow">Reihenfolge
                <input type="number" name="sort_order" value="<?= e($row['sort_order']) ?>" step="10">
            </label>
            <label class="check">
                <input type="checkbox" name="is_active" value="1"<?= (int) $row['is_active'] === 1 ? ' checked' : '' ?>>
                aktiv
            </label>
            <button type="submit" class="btn">Speichern</button>
        </form>
    <?php endforeach; ?>

    <p class="muted small">
        Wohnungen werden nie gelöscht, nur über den Haken deaktiviert – alte Rechnungen verweisen darauf.
    </p>
</section>

<section class="card">
    <h2>Neue Wohnung</h2>
    <form method="post" action="/wohnungen" class="row inline-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="is_active" value="1">
        <label class="grow">Name <input type="text" name="name" maxlength="100" required></label>
        <label class="narrow">Betten <input type="number" name="beds_default" value="2" min="1" step="1"></label>
        <label class="narrow">Reihenfolge <input type="number" name="sort_order" value="0" step="10"></label>
        <button type="submit" class="btn btn-primary">Anlegen</button>
    </form>
</section>

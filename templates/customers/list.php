<?php
/**
 * @var string                         $q
 * @var array<int,array<string,mixed>> $rows
 */
?>
<div class="pagehead">
    <h1>Kunden</h1>
</div>

<form class="filterbar" method="get" action="/kunden">
    <label class="grow">
        Suche
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Firma, Name, Ort oder E-Mail">
    </label>
    <button type="submit" class="btn">Suchen</button>
    <a class="btn btn-plain" href="/kunden">Zurücksetzen</a>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="muted">Keine Kunden gefunden.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="grid">
            <thead>
            <tr>
                <th>Firma</th>
                <th>Ansprechpartner</th>
                <th>Ort</th>
                <th>E-Mail</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= e($row['company']) ?></td>
                    <td><?= e($row['contact_name']) ?></td>
                    <td><?= e(trim($row['postcode'] . ' ' . $row['city'])) ?></td>
                    <td><?= e($row['email']) ?></td>
                    <td class="num"><a href="/kunden/<?= e($row['id']) ?>">bearbeiten</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Neuen Kunden anlegen</h2>
    <form method="post" action="/kunden" class="stack">
        <?= csrfField() ?>
        <div class="row">
            <label class="grow">Firma <input type="text" name="company" maxlength="190"></label>
            <label class="grow">Ansprechpartner <input type="text" name="contact_name" maxlength="190"></label>
        </div>
        <label>Straße <input type="text" name="street" maxlength="190"></label>
        <div class="row">
            <label class="narrow">PLZ <input type="text" name="postcode" maxlength="20"></label>
            <label class="grow">Ort <input type="text" name="city" maxlength="120"></label>
            <label class="narrow">Land <input type="text" name="country_code" value="DE" maxlength="2"></label>
        </div>
        <div class="row">
            <label class="grow">E-Mail <input type="email" name="email" maxlength="190"></label>
            <label class="grow">Telefon <input type="text" name="phone" maxlength="60"></label>
            <label class="grow">USt-IdNr. <input type="text" name="vat_id" maxlength="30"></label>
        </div>
        <label>Notizen <textarea name="notes" rows="2"></textarea></label>
        <button type="submit" class="btn btn-primary">Anlegen</button>
    </form>
</section>

<?php
/** @var array<string,mixed> $customer */
?>
<div class="pagehead">
    <h1><?= e($customer['company'] !== '' ? $customer['company'] : $customer['contact_name']) ?></h1>
    <a class="btn btn-plain" href="/kunden">Zur Liste</a>
</div>

<section class="card">
    <form method="post" action="/kunden/<?= e($customer['id']) ?>" class="stack">
        <?= csrfField() ?>
        <div class="row">
            <label class="grow">Firma <input type="text" name="company" value="<?= e($customer['company']) ?>" maxlength="190"></label>
            <label class="grow">Ansprechpartner <input type="text" name="contact_name" value="<?= e($customer['contact_name']) ?>" maxlength="190"></label>
        </div>
        <label>Straße <input type="text" name="street" value="<?= e($customer['street']) ?>" maxlength="190"></label>
        <div class="row">
            <label class="narrow">PLZ <input type="text" name="postcode" value="<?= e($customer['postcode']) ?>" maxlength="20"></label>
            <label class="grow">Ort <input type="text" name="city" value="<?= e($customer['city']) ?>" maxlength="120"></label>
            <label class="narrow">Land <input type="text" name="country_code" value="<?= e($customer['country_code']) ?>" maxlength="2"></label>
        </div>
        <div class="row">
            <label class="grow">E-Mail <input type="email" name="email" value="<?= e($customer['email']) ?>" maxlength="190"></label>
            <label class="grow">Telefon <input type="text" name="phone" value="<?= e($customer['phone']) ?>" maxlength="60"></label>
            <label class="grow">USt-IdNr. <input type="text" name="vat_id" value="<?= e($customer['vat_id']) ?>" maxlength="30"></label>
        </div>
        <label>Notizen <textarea name="notes" rows="3"><?= e($customer['notes']) ?></textarea></label>
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>

    <p class="muted small">
        Änderungen wirken sich nur auf Entwürfe aus. Finalisierte Rechnungen tragen eine
        eingefrorene Kopie der Adresse und bleiben unberührt.
    </p>
</section>

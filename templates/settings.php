<?php
/**
 * @var array<string,string> $settings
 * @var list<string>         $keys
 */
$labels = [
    'seller_name'         => 'Firma',
    'seller_owner'        => 'Inhaber',
    'seller_street'       => 'Straße',
    'seller_postcode'     => 'PLZ',
    'seller_city'         => 'Ort',
    'seller_country_code' => 'Land',
    'seller_phone'        => 'Telefon',
    'seller_email'        => 'E-Mail',
    'seller_website'      => 'Website',
    'seller_tax_number'   => 'Steuernummer',
    'seller_vat_id'       => 'USt-IdNr.',
    'bank_name'           => 'Bank',
    'bank_iban'           => 'IBAN',
    'bank_bic'            => 'BIC',
    'invoice_subject'     => 'Betreff (Vorgabe)',
    'closing_text'        => 'Schlusstext',
    'payment_terms_text'  => 'Zahlungsbedingungen',
    'number_format'       => 'Nummernformat',
];
$groups = [
    'Absender' => ['seller_name', 'seller_owner', 'seller_street', 'seller_postcode', 'seller_city', 'seller_country_code', 'seller_phone', 'seller_email', 'seller_website', 'seller_tax_number', 'seller_vat_id'],
    'Bank'     => ['bank_name', 'bank_iban', 'bank_bic'],
    'Texte'    => ['invoice_subject', 'closing_text', 'payment_terms_text'],
];
$longFields = ['closing_text', 'payment_terms_text'];
?>
<div class="pagehead">
    <h1>Einstellungen</h1>
</div>

<form method="post" action="/einstellungen">
    <?= csrfField() ?>

    <?php foreach ($groups as $heading => $fields): ?>
        <section class="card">
            <h2><?= e($heading) ?></h2>
            <div class="stack">
                <?php foreach ($fields as $key): ?>
                    <label>
                        <?= e($labels[$key]) ?>
                        <?php if (in_array($key, $longFields, true)): ?>
                            <textarea name="<?= e($key) ?>" rows="4"><?= e($settings[$key] ?? '') ?></textarea>
                        <?php else: ?>
                            <input type="text" name="<?= e($key) ?>" value="<?= e($settings[$key] ?? '') ?>">
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="card">
        <h2>Nummernkreis</h2>
        <div class="row">
            <label class="grow">
                Nummernformat
                <input type="text" name="number_format" value="<?= e($settings['number_format'] ?? '{seq}/{year}') ?>" required>
            </label>
            <label class="check">
                <input type="checkbox" name="number_reset_yearly" value="1"<?= ($settings['number_reset_yearly'] ?? '1') === '1' ? ' checked' : '' ?>>
                jährlich neu bei 1 beginnen
            </label>
        </div>
        <p class="muted small">
            Platzhalter: <code>{seq}</code>, <code>{seq3}</code>, <code>{seq4}</code>,
            <code>{year}</code>, <code>{year2}</code>. <code>{seq}</code> ist Pflicht.
            Das Format nachträglich zu ändern bricht die Lückenlosigkeit des Nummernkreises –
            nur zum Jahreswechsel anfassen.
        </p>
    </section>

    <div class="formbar">
        <button type="submit" class="btn btn-primary">Speichern</button>
    </div>
</form>

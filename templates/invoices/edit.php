<?php
/**
 * Rechnungseditor. Alle Positionszeilen werden von public/assets/app.js
 * erzeugt; der Server liefert nur das Zeilen-Template und den Startbestand
 * als JSON.
 *
 * @var array<string,mixed>            $invoice
 * @var array<int,array<string,mixed>> $apartments
 * @var array<int,array<string,mixed>> $presets
 * @var array<string,string>           $taxOptions
 */
$isNew    = $invoice['id'] === null;
$action   = '/rechnungen/' . ($isNew ? 'neu' : $invoice['id']) . '/speichern';
$customer = $invoice['customer'];

$seed = [
    'items'      => array_map(static fn (array $item): array => [
        'kind'                 => $item['kind'],
        'description'          => $item['description'],
        'apartment_id'         => $item['apartment_id'],
        'apartment_name'       => $item['apartment_name'],
        'date_from'            => $item['date_from'],
        'date_to'              => $item['date_to'],
        'beds'                 => $item['beds'],
        'quantity'             => $item['quantity'],
        'price_cents'          => $item['unit_price_gross_cents'],
        'price_mode'           => $item['price_mode'] ?? 'unit',
        'line_gross_cents'     => $item['line_gross_cents'],
        'tax'                  => $item['tax_category'] . '|' . $item['tax_rate_bp'],
        'tax_exemption_reason' => $item['tax_exemption_reason'] ?? '',
    ], $invoice['items']),
    'presets'    => $presets,
    'apartments' => $apartments,
];
?>
<div class="pagehead">
    <h1><?= $isNew ? 'Neue Rechnung' : 'Entwurf #' . e($invoice['id']) ?></h1>
    <a class="btn btn-plain" href="/rechnungen">Zur Liste</a>
</div>

<script type="application/json" id="editor-data"><?=
    json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
?></script>

<form method="post" action="<?= e($action) ?>" id="rechnung-form">
    <?= csrfField() ?>

    <section class="card cols">
        <div>
            <h2>Eckdaten</h2>
            <label>
                Rechnungsdatum
                <input type="date" name="invoice_date" value="<?= e($invoice['invoice_date']) ?>" required>
            </label>
            <label>
                Betreff
                <input type="text" name="subject" value="<?= e($invoice['subject']) ?>" maxlength="190">
            </label>
            <div class="row">
                <label>
                    Leistung von
                    <input type="date" name="service_from" value="<?= e($invoice['service_from']) ?>">
                </label>
                <label>
                    Leistung bis
                    <input type="date" name="service_to" value="<?= e($invoice['service_to']) ?>">
                </label>
            </div>
        </div>

        <div id="kunde" data-selected="<?= e($invoice['customer_id'] ?? '') ?>">
            <h2>Kunde</h2>

            <div class="customer-search" data-hide-when-selected>
                <label>
                    Kunde suchen
                    <input type="search" id="kundensuche" autocomplete="off" placeholder="Firma, Name, Ort oder E-Mail">
                </label>
                <ul id="kundentreffer" class="suggestions" hidden></ul>
                <p class="muted small">Kein Treffer? Dann einfach die Felder unten ausfüllen – der Kunde wird beim Speichern angelegt.</p>
            </div>

            <input type="hidden" name="customer_id" id="customer_id" value="<?= e($invoice['customer_id'] ?? '') ?>">

            <p data-show-when-selected hidden>
                <strong id="kunde-gewaehlt"></strong>
                <button type="button" class="btn btn-plain" id="kunde-loesen">Auswahl aufheben</button>
            </p>

            <div class="customer-fields">
                <label>Firma <input type="text" name="customer[company]" data-customer="company" value="<?= e($customer['company'] ?? '') ?>" maxlength="190"></label>
                <label>Ansprechpartner <input type="text" name="customer[contact_name]" data-customer="contact_name" value="<?= e($customer['contact_name'] ?? '') ?>" maxlength="190"></label>
                <label>Straße <input type="text" name="customer[street]" data-customer="street" value="<?= e($customer['street'] ?? '') ?>" maxlength="190"></label>
                <div class="row">
                    <label class="narrow">PLZ <input type="text" name="customer[postcode]" data-customer="postcode" value="<?= e($customer['postcode'] ?? '') ?>" maxlength="20"></label>
                    <label class="grow">Ort <input type="text" name="customer[city]" data-customer="city" value="<?= e($customer['city'] ?? '') ?>" maxlength="120"></label>
                    <label class="narrow">Land <input type="text" name="customer[country_code]" data-customer="country_code" value="<?= e($customer['country_code'] ?? 'DE') ?>" maxlength="2"></label>
                </div>
                <div class="row">
                    <label class="grow">E-Mail <input type="email" name="customer[email]" data-customer="email" value="<?= e($customer['email'] ?? '') ?>" maxlength="190"></label>
                    <label class="grow">Telefon <input type="text" name="customer[phone]" data-customer="phone" value="<?= e($customer['phone'] ?? '') ?>" maxlength="60"></label>
                </div>
                <?php /* Die USt-IdNr geben die Firmen in der Praxis nicht an.
                         Das Feld bleibt erhalten - fuer die E-Rechnung ist es
                         nuetzlich -, steht aber nicht mehr im Weg. Ist bereits
                         eine hinterlegt, ist es von vornherein aufgeklappt. */ ?>
                <details class="optional-field"<?= ($customer['vat_id'] ?? '') !== '' ? ' open' : '' ?>>
                    <summary>USt-IdNr. ergänzen <span class="muted small">(optional)</span></summary>
                    <label>USt-IdNr. <input type="text" name="customer[vat_id]" data-customer="vat_id" value="<?= e($customer['vat_id'] ?? '') ?>" maxlength="30"></label>
                </details>
            </div>
        </div>
    </section>

    <section class="card" id="positionen">
        <div class="pagehead pagehead--inner">
            <h2>Positionen</h2>
        </div>

        <p class="muted small">Baustein anklicken, um eine Zeile einzufügen.</p>
        <div class="preset-buttons">
            <?php foreach ($presets as $preset): ?>
                <button type="button" class="btn btn-preset" data-preset="<?= e($preset['id']) ?>">
                    + <?= e($preset['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>

        <table class="grid items">
            <thead>
            <tr>
                <th class="drag-col"></th>
                <th>Beschreibung</th>
                <th>Wohnung</th>
                <th>Anreise</th>
                <th>Abreise</th>
                <th class="num">Betten</th>
                <th class="num">Nächte</th>
                <th class="num">Menge</th>
                <th class="num">Preis brutto</th>
                <th>Steuer</th>
                <th class="num">Summe</th>
                <th></th>
            </tr>
            </thead>
            <tbody id="positions-body"></tbody>
        </table>

        <p class="muted small" id="keine-positionen">Noch keine Positionen.</p>
    </section>

    <section class="card card--narrow">
        <h2>Summen</h2>
        <table class="totals">
            <tbody id="summen-gruppen"></tbody>
            <tfoot>
            <tr class="totals-sum">
                <th>Gesamt brutto</th>
                <td class="num" id="summe-brutto">0,00 €</td>
            </tr>
            </tfoot>
        </table>
        <p class="muted small">
            Vorschau. Verbindlich ist das Ergebnis des Servers nach dem Speichern.
        </p>
    </section>

    <section class="card">
        <h2>Texte</h2>
        <label>
            Zahlungsbedingungen
            <textarea name="payment_terms_text" rows="4"><?= e($invoice['payment_terms_text']) ?></textarea>
        </label>
        <label>
            Schlusstext
            <textarea name="closing_text" rows="2"><?= e($invoice['closing_text']) ?></textarea>
        </label>
    </section>

    <div class="formbar">
        <button type="submit" class="btn btn-primary">Entwurf speichern</button>
    </div>
</form>

<?php if (!$isNew): ?>
    <section class="card">
        <h2>Finalisieren</h2>
        <p class="muted">
            Beim Finalisieren bekommt die Rechnung ihre fortlaufende Nummer und ist danach
            unveränderlich. Nicht gespeicherte Änderungen gehen dabei verloren – also erst speichern.
        </p>
        <form method="post" action="/rechnungen/<?= e($invoice['id']) ?>/finalisieren"
              data-confirm="Rechnung jetzt finalisieren? Danach ist keine Änderung mehr möglich.">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-primary">Jetzt finalisieren</button>
        </form>
    </section>
<?php endif; ?>

<template id="positions-vorlage">
    <tr class="item-row">
        <td class="drag-col"><span class="drag-handle" draggable="true" title="Zum Sortieren ziehen">⠿</span></td>
        <td class="col-description">
            <input type="text" data-field="description" maxlength="255" required>
            <input type="hidden" data-field="kind">
            <input type="hidden" data-field="apartment_name">
            <input type="hidden" data-field="nights">
            <label class="exemption" data-exemption hidden>
                Begründung der Steuerfreiheit
                <input type="text" data-field="tax_exemption_reason" maxlength="255">
            </label>
        </td>
        <td class="col-apartment">
            <select data-field="apartment_id">
                <option value="">–</option>
                <?php foreach ($apartments as $apartment): ?>
                    <option value="<?= e($apartment['id']) ?>" data-beds="<?= e($apartment['beds_default']) ?>">
                        <?= e($apartment['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </td>
        <td class="col-from"><input type="date" data-field="date_from"></td>
        <td class="col-to"><input type="date" data-field="date_to"></td>
        <td class="col-beds num"><input type="number" data-field="beds" min="1" step="1" class="tiny"></td>
        <td class="col-nights num"><output data-output="nights">0</output></td>
        <td class="col-quantity num">
            <input type="text" data-field="quantity" class="tiny" inputmode="decimal">
            <output data-output="quantity">0</output>
        </td>
        <td class="num">
            <input type="text" data-field="unit_price_gross" class="price" inputmode="decimal"
                   placeholder="0,00" required>
        </td>
        <td>
            <select data-field="tax">
                <?php foreach ($taxOptions as $value => $label): ?>
                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <?php /* Beide Richtungen: Tagespreis eingeben und die Summe ergibt sich,
                 oder die Summe eingeben und der Tagespreis ergibt sich. Welches
                 Feld zuletzt bearbeitet wurde, steht in price_mode. */ ?>
        <td class="num">
            <input type="text" data-field="line_gross" class="price" inputmode="decimal"
                   placeholder="0,00" aria-label="Kosten brutto">
            <input type="hidden" data-field="price_mode" value="unit">
        </td>
        <td class="row-actions">
            <button type="button" class="btn btn-icon" data-move="up" title="Nach oben">▲</button>
            <button type="button" class="btn btn-icon" data-move="down" title="Nach unten">▼</button>
            <button type="button" class="btn btn-icon btn-danger" data-remove title="Zeile entfernen">✕</button>
        </td>
    </tr>
</template>

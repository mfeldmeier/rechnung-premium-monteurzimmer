<?php

declare(strict_types=1);

/**
 * Layout der Rechnung als HTML fuer mPDF. Eingebunden von Pmz\Pdf\InvoiceHtml.
 *
 * @var array  $invoice Rechnungs-Array laut InvoiceRepo::load()
 * @var string $logoSrc data:-URI des Logos, leer wenn die Datei fehlt
 */

use Pmz\Domain\LineItem;
use Pmz\Domain\RecipientBlock;
use Pmz\Domain\TaxCategory;
use Pmz\Support\Format;

$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

/** Verbindet bereits escapte Teile und laesst leere weg, damit keine Trenner haengen bleiben. */
$join = static fn (array $parts): string => implode(
    ' &middot; ',
    array_filter($parts, static fn (string $part): bool => $part !== '')
);

$seller   = $invoice['seller'];
$customer = $invoice['customer'];

// Ist ein Anschriftenblock hinterlegt, gewinnt er gegen die Einzelfelder -
// auch bei finalisierten Rechnungen, deren Snapshot daraus entstanden ist.
$recipientLines = ($invoice['customer_freeform'] ?? null) !== null
    ? RecipientBlock::parse((string) $invoice['customer_freeform'])->lines
    : [];

$isDraft  = $invoice['status'] === 'draft';
$isStorno = $invoice['doc_type'] === 'credit_note';
$heading  = $isStorno ? 'Storno-Rechnung' : 'Rechnung';

// Welche Preisbasis in der Positionstabelle steht. Im Netto-Modus zeigt die
// Tabelle Nettobetraege, damit ihre Summe zu den Netto-Zeilen im Summenblock
// passt; die Steuer kommt dann sichtbar darunter dazu.
$grossPrices = (bool) ($invoice['prices_include_tax'] ?? true);
$priceNote   = $grossPrices ? 'inkl. MwSt' : 'zzgl. MwSt';

$footerLine1 = $join([
    $e($seller['name']),
    $e($seller['street']),
    $e(trim($seller['postcode'] . ' ' . $seller['city'])),
    $seller['phone'] !== '' ? 'Tel. ' . $e($seller['phone']) : '',
    $seller['tax_number'] !== '' ? 'SteuerNr ' . $e($seller['tax_number']) : '',
    $seller['vat_id'] !== '' ? 'USt-IdNr ' . $e($seller['vat_id']) : '',
]);

$footerLine2 = $join([
    $seller['email'] !== '' ? $e($seller['email']) : '',
]);

$footerLine3 = $join([
    $seller['bank_name'] !== '' ? 'Bank: ' . $e($seller['bank_name']) : '',
    $seller['bank_iban'] !== '' ? 'IBAN ' . $e($seller['bank_iban']) : '',
    $seller['bank_bic'] !== '' ? 'BIC ' . $e($seller['bank_bic']) : '',
]);

?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
    @page {
        margin-top: 15mm;
        margin-bottom: 30mm;
        margin-left: 20mm;
        margin-right: 20mm;
        margin-footer: 8mm;
    }

    body {
        font-family: dejavusans;
        font-size: 10pt;
        line-height: 1.4;
        color: #1a1a1a;
    }

    table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    /* Ohne Umbruchregel schiebt ein langer Firmenname oder eine Beschreibung
       ohne Leerzeichen die Spalte auf und laeuft ueber den Seitenrand hinaus.
       table-layout: fixed haelt zusaetzlich die Prozentbreiten unten ein. */
    td, th {
        vertical-align: top;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }

    .logo {
        text-align: right;
        margin-bottom: 6mm;
    }

    .heading {
        font-size: 17pt;
        font-weight: bold;
        margin-bottom: 2mm;
    }

    .address td {
        padding: 0;
    }

    .address .left {
        width: 58%;
    }

    .address .right {
        width: 42%;
        text-align: right;
    }

    .subject {
        margin-top: 12mm;
        margin-bottom: 4mm;
    }

    .subject td {
        padding: 0;
        font-weight: bold;
    }

    .subject .left {
        width: 58%;
    }

    .subject .right {
        width: 42%;
        text-align: right;
        font-weight: normal;
    }

    .storno-note {
        margin-bottom: 4mm;
    }

    .items th {
        border-bottom: 0.6pt solid #1a1a1a;
        padding: 1.5mm 2mm;
        font-weight: bold;
        text-align: left;
    }

    .items td {
        border-bottom: 0.3pt solid #bbbbbb;
        padding: 1.5mm 2mm;
    }

    .items .col-qty,
    .items .col-price,
    .items .col-sum {
        text-align: right;
    }

    .items .col-desc   { width: 48%; }
    .items .col-qty    { width: 12%; }
    .items .col-price  { width: 20%; }
    .items .col-sum    { width: 20%; }

    /* Betraege nie umbrechen - "1.234,56" auf zwei Zeilen liest niemand gern.
       Nur die Zellen, nicht die Koepfe: die sollen umbrechen duerfen. */
    .items td.col-qty,
    .items td.col-price,
    .items td.col-sum {
        white-space: nowrap;
    }

    .items .unit-note {
        font-size: 7.5pt;
        font-weight: normal;
        color: #555555;
    }

    .period {
        font-size: 8pt;
        color: #555555;
    }

    /* Der Summenblock darf nicht zwischen "Netto 7 %" und "Brutto Gesamt"
       auf zwei Seiten zerfallen - das liest sich wie zwei Rechnungen. */
    .totals {
        margin-top: 4mm;
        page-break-inside: avoid;
    }

    .totals td {
        padding: 0.8mm 2mm;
    }

    .totals .spacer { width: 42%; }
    .totals .label  { width: 38%; }
    .totals .value  { width: 20%; text-align: right; white-space: nowrap; }

    .totals .grand td {
        border-top: 0.6pt solid #1a1a1a;
        font-weight: bold;
    }

    .terms {
        margin-top: 8mm;
        page-break-inside: avoid;
    }

    .closing {
        margin-top: 8mm;
        page-break-inside: avoid;
    }

    /* Eine einzelne Positionszeile soll nicht quer ueber den Seitenwechsel
       laufen; der Tabellenkopf wiederholt sich dafuer oben. */
    .items tr {
        page-break-inside: avoid;
    }

    .footer {
        font-size: 7pt;
        line-height: 1.35;
        color: #555555;
        text-align: center;
        border-top: 0.3pt solid #bbbbbb;
        padding-top: 1.5mm;
        word-wrap: break-word;
    }

    .footer .pages {
        margin-top: 0.8mm;
    }
</style>
</head>
<body>

<htmlpagefooter name="pmz">
    <div class="footer">
        <div><?= $footerLine1 ?></div>
        <div><?= $footerLine2 ?></div>
        <div><?= $footerLine3 ?></div>
        <?php /* Mehrseitige Rechnungen sollen zeigen, dass nichts fehlt. */ ?>
        <div class="pages">Seite {PAGENO} von {nbpg}</div>
    </div>
</htmlpagefooter>
<sethtmlpagefooter name="pmz" value="on" page="ALL" />

<?php if ($isDraft): ?>
<watermarktext content="ENTWURF" alpha="0.08" />
<?php endif; ?>

<?php if ($logoSrc !== ''): ?>
<div class="logo"><img src="<?= $e($logoSrc) ?>" style="width: 45mm;" alt=""></div>
<?php endif; ?>

<table class="address">
    <tr>
        <td class="left">
            <?php if ($recipientLines !== []): ?>
            <?php /* Freitextmodus: der Block steht genau so auf dem Papier,
                     wie er eingegeben wurde. Das ist sein ganzer Zweck. */ ?>
                <?php foreach ($recipientLines as $line): ?>
            <div><?= $e($line) ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <?php if ($customer['company'] !== ''): ?>
            <div><?= $e($customer['company']) ?></div>
                <?php endif; ?>
                <?php if ($customer['contact_name'] !== ''): ?>
            <div><?= $e($customer['contact_name']) ?></div>
                <?php endif; ?>
                <?php if ($customer['street'] !== ''): ?>
            <div><?= $e($customer['street']) ?></div>
                <?php endif; ?>
            <div><?= $e(trim($customer['postcode'] . ' ' . $customer['city'])) ?></div>
            <?php endif; ?>
        </td>
        <td class="right">
            <div class="heading"><?= $e($heading) ?></div>
            <div>Datum: <?= $e(Format::date($invoice['invoice_date'])) ?></div>
        </td>
    </tr>
</table>

<table class="subject">
    <tr>
        <td class="left"><?= $e($invoice['subject']) ?></td>
        <td class="right">
            <?php if (!$isDraft && $invoice['number'] !== null): ?>
            Rechnungsnummer: <?= $e($invoice['number']) ?>
            <?php endif; ?>
        </td>
    </tr>
</table>

<?php if ($isStorno && ($invoice['cancel_reason'] ?? '') !== ''): ?>
<div class="storno-note">Stornogrund: <?= $e($invoice['cancel_reason']) ?></div>
<?php endif; ?>

<table class="items">
    <thead>
        <tr>
            <th class="col-desc">Beschreibung</th>
            <th class="col-qty">Betten/<wbr>N&auml;chte</th>
            <?php /* <br> statt eines Blockelements: mPDF setzt ein display:block
                     im <th> trotzdem inline, und dann klebt "inkl. MwSt" am Wort
                     davor. */ ?>
            <th class="col-price">Preis je Nacht u. Bett<br><span class="unit-note"><?= $e($priceNote) ?></span></th>
            <th class="col-sum">Kosten<br><span class="unit-note"><?= $e($priceNote) ?></span></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($invoice['items'] as $item): ?>
        <tr>
            <td class="col-desc">
                <?= $e($item['description']) ?>
                <?php if ($item['date_from'] !== null && $item['date_to'] !== null): ?>
                <div class="period"><?= $e(Format::dateRange($item['date_from'], $item['date_to'])) ?></div>
                <?php endif; ?>
            </td>
            <td class="col-qty">
                <?php if ($item['kind'] === LineItem::KIND_ACCOMMODATION && $item['beds'] !== null && $item['nights'] !== null): ?>
                <?= $e($item['beds'] . '/' . $item['nights']) ?>
                <?php endif; ?>
            </td>
            <?php
            // Im Netto-Modus steht in der Tabelle der Nettobetrag der Zeile,
            // und zwar der vom Calculator verteilte: nur der summiert sich
            // exakt auf die Netto-Zeilen im Block darunter.
            $lineCents = $grossPrices ? $item['line_gross_cents'] : $item['line_net_cents'];
            $quantity  = (float) $item['quantity'];

            // Bei vorgegebenem Endpreis ist der Nachtpreis abgeleitet und
            // selten glatt. Deshalb mit bis zu vier Nachkommastellen, damit
            // die Multiplikation zur Zeilensumme aufgeht und niemand beim
            // Nachrechnen auf eine Differenz stoesst.
            $derived   = !$grossPrices || ($item['price_mode'] ?? 'unit') === 'total';
            $unitCents = ($derived && $quantity > 0)
                ? $lineCents / $quantity
                : (float) $item['unit_price_gross_cents'];
            ?>
            <td class="col-price"><?= $e(Format::unitPrice($unitCents)) ?></td>
            <td class="col-sum"><?= $e(Format::money($lineCents)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<table class="totals">
    <?php foreach ($invoice['tax_breakdown'] as $group): ?>
        <?php if ($group['category'] === TaxCategory::EXEMPT): ?>
    <tr>
        <td class="spacer"></td>
        <td class="label"><?= $e($group['exemption_reason'] ?? '') ?></td>
        <td class="value"><?= $e(Format::euro($group['gross_cents'])) ?></td>
    </tr>
        <?php else: ?>
    <tr>
        <td class="spacer"></td>
        <td class="label">Netto <?= $e(Format::percent($group['rate_bp'])) ?>&nbsp;%</td>
        <td class="value"><?= $e(Format::euro($group['net_cents'])) ?></td>
    </tr>
    <tr>
        <td class="spacer"></td>
        <td class="label"><?= $grossPrices ? 'davon' : 'zzgl.' ?> <?= $e(Format::percent($group['rate_bp'])) ?>&nbsp;% MwSt</td>
        <td class="value"><?= $e(Format::euro($group['tax_cents'])) ?></td>
    </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <tr class="grand">
        <td class="spacer"></td>
        <td class="label">Brutto Gesamt</td>
        <td class="value"><?= $e(Format::euro($invoice['gross_total_cents'])) ?></td>
    </tr>
</table>

<?php if ($invoice['payment_terms_text'] !== ''): ?>
<div class="terms"><?= $e($invoice['payment_terms_text']) ?></div>
<?php endif; ?>

<div class="closing">
    <?php if ($invoice['closing_text'] !== ''): ?>
    <div><?= $e($invoice['closing_text']) ?></div>
    <?php endif; ?>
    <?php if ($seller['owner'] !== ''): ?>
    <div><?= $e($seller['owner']) ?></div>
    <?php endif; ?>
</div>

</body>
</html>

<?php

declare(strict_types=1);

/**
 * Layout der Rechnung als HTML fuer mPDF. Eingebunden von Pmz\Pdf\InvoiceHtml.
 *
 * @var array  $invoice Rechnungs-Array laut InvoiceRepo::load()
 * @var string $logoSrc data:-URI des Logos, leer wenn die Datei fehlt
 */

use Pmz\Domain\LineItem;
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

$isDraft  = $invoice['status'] === 'draft';
$isStorno = $invoice['doc_type'] === 'credit_note';
$heading  = $isStorno ? 'Storno-Rechnung' : 'Rechnung';

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
    }

    td, th {
        vertical-align: top;
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

    .address .right {
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

    .subject .right {
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

    .items .col-desc   { width: 46%; }
    .items .col-qty    { width: 13%; }
    .items .col-price  { width: 23%; }
    .items .col-sum    { width: 18%; }

    .period {
        font-size: 8pt;
        color: #555555;
    }

    .totals {
        margin-top: 4mm;
    }

    .totals td {
        padding: 0.8mm 2mm;
    }

    .totals .spacer { width: 52%; }
    .totals .label  { width: 30%; }
    .totals .value  { width: 18%; text-align: right; }

    .totals .grand td {
        border-top: 0.6pt solid #1a1a1a;
        font-weight: bold;
    }

    .terms {
        margin-top: 8mm;
    }

    .closing {
        margin-top: 8mm;
    }

    .footer {
        font-size: 7pt;
        line-height: 1.35;
        color: #555555;
        text-align: center;
        border-top: 0.3pt solid #bbbbbb;
        padding-top: 1.5mm;
    }
</style>
</head>
<body>

<htmlpagefooter name="pmz">
    <div class="footer">
        <div><?= $footerLine1 ?></div>
        <div><?= $footerLine2 ?></div>
        <div><?= $footerLine3 ?></div>
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
        <td>
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
        </td>
        <td class="right">
            <div class="heading"><?= $e($heading) ?></div>
            <div>Datum: <?= $e(Format::date($invoice['invoice_date'])) ?></div>
        </td>
    </tr>
</table>

<table class="subject">
    <tr>
        <td><?= $e($invoice['subject']) ?></td>
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
            <th class="col-qty">Betten/N&auml;chte</th>
            <th class="col-price">Preis pro Nacht u. Bett Brutto</th>
            <th class="col-sum">Kosten</th>
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
            // Bei vorgegebenem Endpreis ist der Nachtpreis abgeleitet und
            // selten glatt. Deshalb mit bis zu vier Nachkommastellen, damit
            // die Multiplikation zur Zeilensumme aufgeht und niemand beim
            // Nachrechnen auf eine Differenz stoesst.
            $unitCents = (($item['price_mode'] ?? 'unit') === 'total' && (float) $item['quantity'] > 0)
                ? $item['line_gross_cents'] / (float) $item['quantity']
                : (float) $item['unit_price_gross_cents'];
            ?>
            <td class="col-price"><?= $e(Format::unitPrice($unitCents)) ?></td>
            <td class="col-sum"><?= $e(Format::money($item['line_gross_cents'])) ?></td>
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
        <td class="label">davon <?= $e(Format::percent($group['rate_bp'])) ?>&nbsp;% MwSt</td>
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

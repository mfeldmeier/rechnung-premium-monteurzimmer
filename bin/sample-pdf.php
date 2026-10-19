<?php

declare(strict_types=1);

/**
 * Erzeugt Muster-PDFs mit absichtlich unangenehmen Werten: sehr langer
 * Firmenname, Beschreibung ohne Leerzeichen, vierstelliger Betrag, lange
 * Begruendung der Steuerbefreiung, mehr Positionen als auf eine Seite passen.
 *
 * Damit laesst sich das Layout pruefen, ohne eine Datenbank oder einen
 * Browser zu bemuehen - kein Datensatz wird angefasst.
 *
 * Aufruf: php bin/sample-pdf.php [Zielverzeichnis] [Zahl der Fuellzeilen]
 *
 * Die Fuellzeilen steuern die Laenge: mit 14 bricht die Rechnung um, mit 0
 * passt alles auf eine Seite und der Summenblock ist ohne Blaettern zu sehen.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pmz\Domain\Calculator;
use Pmz\Domain\LineItem;
use Pmz\Domain\TaxCategory;
use Pmz\Domain\Totals;
use Pmz\Pdf\PdfRenderer;

$target = $argv[1] ?? sys_get_temp_dir();
$filler = isset($argv[2]) ? max(0, (int) $argv[2]) : 14;

$seller = [
    'name'       => 'REHER Immobilien & Premium Monteurzimmer Kassel',
    'owner'      => 'Marvin Reher',
    'street'     => 'Musterstrasse 123a',
    'postcode'   => '34117',
    'city'       => 'Kassel',
    'phone'      => '+49 561 1234567',
    'email'      => 'rechnung@premium-monteurzimmer.com',
    'tax_number' => '026 123 45678',
    'vat_id'     => 'DE123456789',
    'bank_name'  => 'Kasseler Sparkasse',
    'bank_iban'  => 'DE02 5205 0353 0001 2345 67',
    'bank_bic'   => 'HELADEF1KAS',
];

$customer = [
    'company'      => 'Nordwestdeutsche Anlagenbau- und Industriemontagegesellschaft mbH & Co. KG',
    'contact_name' => 'Hans-Werner Oberhausermann-Schmittdorf',
    'street'       => 'Industriepark Nord, Gebaeude 7, Zufahrt ueber Werkstor 3',
    'postcode'     => '49084',
    'city'         => 'Osnabrueck-Hafengebiet',
    'country_code' => 'DE',
    'email'        => 'rechnungseingang@nordwestdeutsche-anlagenbau.example',
    'phone'        => '+49 541 9876543',
    'vat_id'       => 'DE987654321',
];

/** @return LineItem[] */
function musterPositionen(bool $pricesIncludeTax, int $filler): array
{
    $items = [
        new LineItem(
            kind: LineItem::KIND_ACCOMMODATION,
            description: 'Wohnung Kiwi, Doppelzimmer mit Kuechenzeile, Stellplatz und Waschmaschinennutzung',
            quantity: 44.0,
            unitPriceGrossCents: 2800,
            taxRateBp: 700,
            apartmentName: 'Kiwi',
            beds: 2,
            nights: 22,
            dateFrom: '2026-10-09',
            dateTo: '2026-10-31',
            priceIncludesTax: $pricesIncludeTax,
        ),
        new LineItem(
            kind: LineItem::KIND_ACCOMMODATION,
            description: 'WohnungAnanasLangbezeichnungOhneLeerzeichenDieDieSpalteSprengenWuerde',
            quantity: 60.0,
            unitPriceGrossCents: 0,
            taxRateBp: 700,
            apartmentName: 'Ananas',
            beds: 4,
            nights: 15,
            dateFrom: '2026-11-01',
            dateTo: '2026-11-16',
            priceMode: LineItem::MODE_TOTAL,
            lineTotalGrossCents: 198750,
            priceIncludesTax: $pricesIncludeTax,
        ),
        new LineItem(
            kind: LineItem::KIND_CLEANING,
            description: 'Endreinigung inklusive Waesche, Bettbezuege und Uebergabeprotokoll',
            quantity: 2.0,
            unitPriceGrossCents: 6000,
            taxRateBp: 1900,
            priceIncludesTax: $pricesIncludeTax,
        ),
        new LineItem(
            kind: LineItem::KIND_DEPOSIT,
            description: 'Kaution',
            quantity: 1.0,
            unitPriceGrossCents: 100000,
            taxRateBp: 0,
            taxCategory: TaxCategory::EXEMPT,
            taxExemptionReason: 'Durchlaufender Posten, keine Gegenleistung im Sinne des Paragraf 10 Absatz 1 Satz 6 UStG',
            priceIncludesTax: $pricesIncludeTax,
        ),
    ];

    // Genug Zeilen fuer einen Seitenumbruch - dort zeigt sich, ob Kopf,
    // Fusszeile und Summenblock sauber trennen.
    for ($i = 1; $i <= $filler; $i++) {
        $items[] = new LineItem(
            kind: LineItem::KIND_CUSTOM,
            description: "Zusatzleistung {$i}: Parkplatzmiete, Bettwaeschewechsel und Schluesselnachfertigung",
            quantity: 3.0,
            unitPriceGrossCents: 1250 + $i,
            taxRateBp: 1900,
            priceIncludesTax: $pricesIncludeTax,
        );
    }

    return $items;
}

/**
 * @param  LineItem[] $items
 * @return array<int,array<string,mixed>>
 */
function alsZeilen(array $items, Totals $totals): array
{
    $out = [];

    foreach ($items as $i => $item) {
        $out[] = [
            'kind'                   => $item->kind,
            'description'            => $item->description,
            'apartment_id'           => null,
            'apartment_name'         => $item->apartmentName,
            'date_from'              => $item->dateFrom,
            'date_to'                => $item->dateTo,
            'beds'                   => $item->beds,
            'nights'                 => $item->nights,
            'quantity'               => $item->quantity,
            'unit_price_gross_cents' => (int) round($item->unitPriceCents()),
            'price_mode'             => $item->priceMode,
            'input_unit_price_cents' => $item->unitPriceGrossCents,
            'input_line_cents'       => $item->lineTotalGrossCents
                ?? (int) round($item->unitPriceGrossCents * $item->quantity),
            'tax_rate_bp'            => $item->taxRateBp,
            'tax_category'           => $item->taxCategory,
            'tax_exemption_reason'   => $item->taxExemptionReason,
            'line_gross_cents'       => $totals->lineGrossCents[$i],
            'line_net_cents'         => $totals->lineNetCents[$i],
        ];
    }

    return $out;
}

$renderer = new PdfRenderer();

foreach (['brutto' => true, 'netto' => false] as $label => $pricesIncludeTax) {
    $items  = musterPositionen($pricesIncludeTax, $filler);
    $totals = Calculator::compute($items);

    $invoice = [
        'id'                 => 999,
        // Einmal finalisiert, einmal Entwurf - so ist auch das Wasserzeichen
        // und die fehlende Rechnungsnummer einmal zu sehen.
        'status'             => $pricesIncludeTax ? 'final' : 'draft',
        'doc_type'           => 'invoice',
        'number'             => $pricesIncludeTax ? '69/2026' : null,
        'invoice_date'       => '2026-10-19',
        'service_from'       => '2026-10-09',
        'service_to'         => '2026-11-16',
        'subject'            => 'Unterbringung Ihrer Monteure im Zeitraum Oktober bis November 2026',
        'customer_id'        => $pricesIncludeTax ? 1 : null,
        // Einmal aus den Stammdaten, einmal als Anschriftenblock - beide Wege
        // muessen im Adresskopf gleich sauber aussehen.
        'customer_freeform'  => $pricesIncludeTax ? null : "Musterbau GmbH & Co. KG\n"
            . "z. Hd. Frau Meier, Einkauf\nIndustriepark Nord 7, Zufahrt über Werkstor 3\n49084 Osnabrück",
        'customer'           => $customer,
        'seller'             => $seller,
        'payment_terms_text' => 'Zahlbar ohne Abzug innerhalb von 14 Tagen nach Rechnungserhalt auf das unten '
            . 'genannte Konto. Bitte geben Sie die Rechnungsnummer als Verwendungszweck an.',
        'closing_text'       => 'Vielen Dank fuer Ihren Auftrag und die gute Zusammenarbeit.',
        'prices_include_tax' => $pricesIncludeTax,
        'items'              => alsZeilen($items, $totals),
        'tax_breakdown'      => $totals->breakdownToArray(),
        'net_total_cents'    => $totals->netTotalCents,
        'tax_total_cents'    => $totals->taxTotalCents,
        'gross_total_cents'  => $totals->grossTotalCents,
        'cancels_invoice_id' => null,
        'cancel_reason'      => null,
        'pdf_filename'       => null,
        'finalized_at'       => $pricesIncludeTax ? '2026-10-19 11:05:00' : null,
    ];

    $path = rtrim($target, '/') . "/muster-rechnung-{$label}.pdf";
    // Wie die Vorschau im Editor: ohne PDF/A, dafuer mit Entwurfswasserzeichen.
    file_put_contents($path, $renderer->render($invoice, archival: false));

    printf(
        "%s: %s (%d Positionen, brutto gesamt %s EUR)\n",
        $label,
        $path,
        count($items),
        number_format($totals->grossTotalCents / 100, 2, ',', '.'),
    );
}

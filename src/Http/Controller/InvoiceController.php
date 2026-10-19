<?php

declare(strict_types=1);

namespace Pmz\Http\Controller;

use DomainException;
use InvalidArgumentException;
use Pmz\Domain\LineItem;
use Pmz\Domain\TaxCategory;
use Pmz\Http\Flash;
use Pmz\Http\Request;
use Pmz\Http\Response;
use Pmz\Http\View;
use Pmz\Pdf\InvoiceDocument;
use Pmz\Repo\ApartmentRepo;
use Pmz\Repo\CustomerRepo;
use Pmz\Repo\InvoiceRepo;
use Pmz\Repo\PresetRepo;
use Pmz\Repo\SettingsRepo;
use Pmz\Repo\StatsRepo;
use Pmz\Service\InvoiceService;

final class InvoiceController extends BaseController
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly InvoiceRepo $invoices = new InvoiceRepo(),
        private readonly InvoiceService $service = new InvoiceService(),
        private readonly CustomerRepo $customers = new CustomerRepo(),
        private readonly ApartmentRepo $apartments = new ApartmentRepo(),
        private readonly PresetRepo $presets = new PresetRepo(),
        private readonly SettingsRepo $settings = new SettingsRepo(),
        private readonly StatsRepo $stats = new StatsRepo(),
        private readonly InvoiceDocument $document = new InvoiceDocument(),
    ) {
    }

    public function index(Request $r): Response
    {
        $this->requireUser();

        $status = (string) $r->query('status', '');
        if (!in_array($status, ['draft', 'final', 'cancelled'], true)) {
            $status = '';
        }

        $filter = [
            'status' => $status,
            'year'   => (int) $r->query('jahr', '0'),
            'q'      => trim((string) $r->query('q', '')),
        ];

        $page   = max(1, (int) $r->query('seite', '1'));
        $result = $this->invoices->paginate($filter, $page, self::PER_PAGE);

        return View::page('invoices/list', [
            'title'    => 'Rechnungen',
            'active'   => 'invoices',
            'filter'   => $filter,
            'rows'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'perPage'  => self::PER_PAGE,
            'lastPage' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'years'    => $this->stats->years(),
        ]);
    }

    public function create(Request $r): Response
    {
        $this->requireUser();

        return $this->editor($this->blankInvoice());
    }

    public function show(Request $r, string $id): Response
    {
        $this->requireUser();

        $invoice = $this->invoices->load((int) $id);
        if ($invoice === null) {
            return $this->notFound('Diese Rechnung gibt es nicht.');
        }

        if ($invoice['status'] === 'draft') {
            return $this->editor($invoice);
        }

        return View::page('invoices/show', [
            'title'   => 'Rechnung ' . ($invoice['number'] ?? ''),
            'active'  => 'invoices',
            'invoice' => $invoice,
        ]);
    }

    public function save(Request $r, string $id): Response
    {
        $user = $this->requireUser();
        $this->guardPost($r);

        $invoiceId  = $this->resolveId($id);
        $customerId = $this->resolveCustomer($r);
        $input      = $this->inputFromRequest($r, $customerId);

        try {
            $savedId = $this->service->saveDraft($invoiceId, $input, (int) $user['id']);
        } catch (DomainException | InvalidArgumentException $e) {
            Flash::error($e->getMessage());

            // Mit den abgeschickten Werten neu rendern, sonst ist eine halbe
            // Stunde Tipparbeit wegen eines Datumsdrehers verloren.
            return $this->editor($this->previewInvoice($invoiceId, $input), 422);
        }

        Flash::success('Entwurf gespeichert.');

        return Response::redirect('/rechnungen/' . $savedId);
    }

    public function finalize(Request $r, string $id): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        $invoiceId = (int) $id;

        try {
            $invoice = $this->service->finalize($invoiceId);
            Flash::success('Rechnung ' . $invoice['number'] . ' wurde finalisiert.');
        } catch (DomainException $e) {
            Flash::error($e->getMessage());
        }

        return Response::redirect('/rechnungen/' . $invoiceId);
    }

    public function cancel(Request $r, string $id): Response
    {
        $this->requireUser();
        $this->guardPost($r);

        $invoiceId = (int) $id;
        $reason    = trim((string) $r->post('cancel_reason', ''));

        if ($reason === '') {
            Flash::error('Bitte einen Stornogrund angeben.');

            return Response::redirect('/rechnungen/' . $invoiceId);
        }

        try {
            $stornoId = $this->service->cancel($invoiceId, $reason);
        } catch (DomainException $e) {
            Flash::error($e->getMessage());

            return Response::redirect('/rechnungen/' . $invoiceId);
        }

        Flash::success('Storno erstellt.');

        return Response::redirect('/rechnungen/' . $stornoId);
    }

    public function pdf(Request $r, string $id): Response
    {
        $this->requireUser();

        $invoiceId = (int) $id;
        $invoice   = $this->invoices->load($invoiceId);

        if ($invoice === null) {
            return $this->notFound('Diese Rechnung gibt es nicht.');
        }

        // Ein Entwurf hat keine Nummer, und ohne Nummer gibt es kein gueltiges
        // ZUGFeRD-XML (BT-1 ist Pflicht). Darum erst finalisieren.
        if ($invoice['status'] === 'draft') {
            Flash::error('Entwürfe haben noch keine Rechnungsnummer. Bitte zuerst finalisieren.');

            return Response::redirect('/rechnungen/' . $invoiceId);
        }

        return Response::download(
            $this->document->createHybrid($invoice),
            $this->pdfFilename($invoice),
            'application/pdf',
        );
    }

    /** @param array<string,mixed> $invoice */
    private function editor(array $invoice, int $status = 200): Response
    {
        $apartments = array_map(static fn (array $a): array => [
            'id'           => (int) $a['id'],
            'name'         => (string) $a['name'],
            'beds_default' => (int) $a['beds_default'],
        ], $this->apartments->allActive());

        $presets = array_map(static fn (array $p): array => [
            'id'                   => (int) $p['id'],
            'label'                => (string) $p['label'],
            'kind'                 => (string) $p['kind'],
            'description_template' => (string) $p['description_template'],
            'default_price_cents'  => $p['default_unit_price_gross_cents'] !== null
                ? (int) $p['default_unit_price_gross_cents']
                : null,
            'price_is_manual'      => (int) $p['price_is_manual'] === 1,
            'tax_rate_bp'          => (int) $p['tax_rate_bp'],
            'tax_category'         => (string) $p['tax_category'],
            'tax_exemption_reason' => $p['tax_exemption_reason'] !== null
                ? (string) $p['tax_exemption_reason']
                : '',
        ], $this->presets->allActive());

        return View::page('invoices/edit', [
            'title'      => $invoice['id'] === null ? 'Neue Rechnung' : 'Entwurf bearbeiten',
            'active'     => 'invoices',
            'invoice'    => $invoice,
            'apartments' => $apartments,
            'presets'    => $presets,
            'taxOptions' => $this->taxOptions(),
        ], $status);
    }

    /**
     * Die erlaubten Steuer-Kombinationen als ein Wert. Ein freier Satz plus
     * freie Kategorie liesse Kombinationen zu, die LineItem sofort ablehnt
     * (Kategorie ungleich S verlangt Satz 0).
     *
     * @return array<string,string>
     */
    private function taxOptions(): array
    {
        return [
            'S|700'  => '7 % (Übernachtung)',
            'S|1900' => '19 % (Regelsatz)',
            'E|0'    => 'steuerfrei (Begründung nötig)',
        ];
    }

    /** @return array<string,mixed> */
    private function blankInvoice(): array
    {
        return [
            'id'                 => null,
            'status'             => 'draft',
            'doc_type'           => 'invoice',
            'number'             => null,
            'invoice_date'       => date('Y-m-d'),
            'service_from'       => null,
            'service_to'         => null,
            'subject'            => $this->settings->get('invoice_subject'),
            'customer_id'        => null,
            'customer'           => $this->customers->snapshot(null),
            'seller'             => $this->settings->seller(),
            'payment_terms_text' => $this->settings->get('payment_terms_text'),
            'closing_text'       => $this->settings->get('closing_text'),
            'items'              => [],
            'tax_breakdown'      => [],
            'net_total_cents'    => 0,
            'tax_total_cents'    => 0,
            'gross_total_cents'  => 0,
            'cancels_invoice_id' => null,
            'cancel_reason'      => null,
            'pdf_filename'       => null,
            'finalized_at'       => null,
        ];
    }

    /**
     * Baut aus den abgeschickten Rohdaten dieselbe Struktur, die
     * InvoiceRepo::load() liefert - damit das Editor-Template nur eine
     * einzige Form kennen muss.
     *
     * @param  array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function previewInvoice(?int $invoiceId, array $input): array
    {
        $invoice = $this->blankInvoice();

        $invoice['id']                 = $invoiceId;
        $invoice['invoice_date']       = (string) $input['invoice_date'];
        $invoice['service_from']       = $input['service_from'];
        $invoice['service_to']         = $input['service_to'];
        $invoice['subject']            = (string) $input['subject'];
        $invoice['customer_id']        = $input['customer_id'];
        $invoice['payment_terms_text'] = (string) $input['payment_terms_text'];
        $invoice['closing_text']       = (string) $input['closing_text'];
        $invoice['customer']           = $this->customers->snapshot(
            $input['customer_id'] !== null ? $this->customers->find((int) $input['customer_id']) : null,
        );

        // Vorschau nach einem Fehler: bei vorgegebenem Endpreis bleibt dieser
        // stehen, sonst ergibt sich die Summe aus dem Tagespreis.
        $invoice['items'] = array_map(static function (array $item): array {
            $item['line_gross_cents'] = ($item['price_mode'] ?? LineItem::MODE_UNIT) === LineItem::MODE_TOTAL
                ? (int) ($item['line_gross_cents'] ?? 0)
                : (int) round($item['unit_price_gross_cents'] * $item['quantity']);
            $item['line_net_cents'] = 0;

            return $item;
        }, $input['items']);

        return $invoice;
    }

    /**
     * Legt bei Bedarf einen Neukunden an. Ist eine Kundennummer gewaehlt,
     * gewinnt sie - die Adressfelder sind dann nur Anzeige.
     */
    private function resolveCustomer(Request $r): ?int
    {
        $selected = trim((string) $r->post('customer_id', ''));
        if ($selected !== '' && (int) $selected > 0) {
            return (int) $selected;
        }

        $fields = $r->postArray('customer');
        $data   = [];
        foreach (['company', 'contact_name', 'street', 'postcode', 'city', 'country_code', 'email', 'phone', 'vat_id'] as $key) {
            $data[$key] = is_scalar($fields[$key] ?? null) ? trim((string) $fields[$key]) : '';
        }

        // Ohne Firma und ohne Ansprechpartner ist das kein Kunde, sondern ein
        // leeres Formular - dann bleibt der Entwurf eben kundenlos.
        if ($data['company'] === '' && $data['contact_name'] === '') {
            return null;
        }

        return $this->customers->create($data);
    }

    /** @return array<string,mixed> Eingabe im Format, das InvoiceService::saveDraft erwartet. */
    private function inputFromRequest(Request $r, ?int $customerId): array
    {
        $items = [];

        foreach ($r->postArray('items') as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $item = $this->itemFromRequest($raw);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        return [
            'invoice_date'       => $this->dateOrToday((string) $r->post('invoice_date', '')),
            'service_from'       => $this->nullIfBlank($r->post('service_from')),
            'service_to'         => $this->nullIfBlank($r->post('service_to')),
            'subject'            => trim((string) $r->post('subject', '')),
            'customer_id'        => $customerId,
            'payment_terms_text' => (string) $r->post('payment_terms_text', ''),
            'closing_text'       => (string) $r->post('closing_text', ''),
            'items'              => $items,
        ];
    }

    /**
     * @param  array<array-key,mixed> $raw
     * @return array<string,mixed>|null null fuer komplett leere Zeilen
     */
    private function itemFromRequest(array $raw): ?array
    {
        $description = trim((string) ($raw['description'] ?? ''));
        $priceCents  = self::parsePriceCents((string) ($raw['unit_price_gross'] ?? ''));
        $totalCents  = self::parsePriceCents((string) ($raw['line_gross'] ?? ''));
        $dateFrom    = $this->nullIfBlank($raw['date_from'] ?? null);
        $dateTo      = $this->nullIfBlank($raw['date_to'] ?? null);

        if ($description === '' && $priceCents === 0 && $totalCents === 0 && $dateFrom === null && $dateTo === null) {
            return null;
        }

        // Massgeblich ist das Feld, in dem zuletzt getippt wurde - die
        // Oberflaeche meldet das in price_mode.
        $priceMode = ($raw['price_mode'] ?? LineItem::MODE_UNIT) === LineItem::MODE_TOTAL
            ? LineItem::MODE_TOTAL
            : LineItem::MODE_UNIT;

        [$category, $rateBp] = $this->splitTax((string) ($raw['tax'] ?? 'S|1900'));

        $kind = (string) ($raw['kind'] ?? LineItem::KIND_CUSTOM);
        if (!in_array($kind, [
            LineItem::KIND_ACCOMMODATION,
            LineItem::KIND_CLEANING,
            LineItem::KIND_DEPOSIT,
            LineItem::KIND_CUSTOM,
        ], true)) {
            $kind = LineItem::KIND_CUSTOM;
        }

        $quantity = (float) str_replace(',', '.', (string) ($raw['quantity'] ?? '1'));

        return [
            'kind'                   => $kind,
            'description'            => $description,
            'apartment_id'           => $this->intOrNull($raw['apartment_id'] ?? null),
            'apartment_name'         => $this->nullIfBlank($raw['apartment_name'] ?? null),
            'date_from'              => $dateFrom,
            'date_to'                => $dateTo,
            'beds'                   => $this->intOrNull($raw['beds'] ?? null),
            'nights'                 => $this->intOrNull($raw['nights'] ?? null),
            'quantity'               => $quantity > 0 ? $quantity : 1.0,
            'unit_price_gross_cents' => $priceCents,
            'price_mode'             => $priceMode,
            'line_gross_cents'       => $totalCents,
            'tax_rate_bp'            => $rateBp,
            'tax_category'           => $category,
            'tax_exemption_reason'   => $this->nullIfBlank($raw['tax_exemption_reason'] ?? null),
        ];
    }

    /** @return array{0:string,1:int} */
    private function splitTax(string $value): array
    {
        $parts    = explode('|', $value, 2);
        $category = TaxCategory::isValid($parts[0] ?? '') ? $parts[0] : TaxCategory::STANDARD;
        $rateBp   = (int) ($parts[1] ?? 0);

        return [$category, $category === TaxCategory::STANDARD ? $rateBp : 0];
    }

    /**
     * Muss sich genauso verhalten wie parsePriceCents() in public/assets/app.js,
     * sonst weicht die Live-Summe im Browser vom gespeicherten Wert ab.
     */
    public static function parsePriceCents(string $text): int
    {
        $clean = preg_replace('/[^0-9,.\-]/', '', trim($text)) ?? '';
        if ($clean === '') {
            return 0;
        }

        $lastComma = strrpos($clean, ',');
        $lastDot   = strrpos($clean, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            // Deutsche Schreibweise: Komma trennt die Cent, Punkte sind Tausender.
            $clean = str_replace(',', '.', str_replace('.', '', $clean));
        } elseif ($lastComma === false && preg_match('/\.\d{3}$/', $clean) === 1) {
            // "1.000" meint tausend, nicht eins. Genau drei Ziffern hinter dem
            // letzten Punkt gibt es bei Centbetraegen nicht.
            $clean = str_replace('.', '', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return (int) round(((float) $clean) * 100);
    }

    /** @param array<string,mixed> $invoice */
    private function pdfFilename(array $invoice): string
    {
        $number = (string) ($invoice['number'] ?? '');
        $label  = $invoice['doc_type'] === 'credit_note' ? 'Storno' : 'Rechnung';
        $number = $number !== '' ? str_replace('/', '-', $number) : (string) $invoice['id'];

        return "{$label}-{$number}.pdf";
    }

    private function resolveId(string $raw): ?int
    {
        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }

    private function dateOrToday(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : date('Y-m-d');
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }
}

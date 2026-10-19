<?php

declare(strict_types=1);

namespace Pmz\Service;

use DomainException;
use PDO;
use Pmz\Domain\Calculator;
use Pmz\Domain\LineItem;
use Pmz\Domain\NumberGenerator;
use Pmz\Domain\TaxCategory;
use Pmz\Domain\Totals;
use Pmz\Repo\CustomerRepo;
use Pmz\Repo\InvoiceRepo;
use Pmz\Repo\SettingsRepo;
use Pmz\Support\Db;

final class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepo $invoices = new InvoiceRepo(),
        private readonly SettingsRepo $settings = new SettingsRepo(),
        private readonly CustomerRepo $customers = new CustomerRepo(),
    ) {
    }

    /**
     * Legt einen Entwurf an oder aktualisiert ihn. Entwuerfe tragen bewusst
     * keine Nummer - die kommt erst beim Finalisieren.
     */
    public function saveDraft(?int $id, array $input, ?int $userId = null): int
    {
        if ($id !== null) {
            $existing = $this->invoices->findRaw($id);
            if ($existing === null) {
                throw new DomainException("Rechnung {$id} existiert nicht.");
            }
            if ($existing['status'] !== 'draft') {
                throw new DomainException(
                    'Finalisierte Rechnungen sind unveraenderlich. Fuer eine Korrektur ein Storno erzeugen.'
                );
            }
        }

        $itemsInput = array_values($input['items'] ?? []);
        $lineItems  = array_map($this->toLineItem(...), $itemsInput);
        $totals     = Calculator::compute($lineItems);

        $row = [
            'invoice_date'       => $input['invoice_date'] ?? date('Y-m-d'),
            'service_from'       => $this->nullIfBlank($input['service_from'] ?? null),
            'service_to'         => $this->nullIfBlank($input['service_to'] ?? null),
            'subject'            => (string) ($input['subject'] ?? $this->settings->get('invoice_subject')),
            'customer_id'        => isset($input['customer_id']) && $input['customer_id'] !== ''
                ? (int) $input['customer_id']
                : null,
            'payment_terms_text' => (string) ($input['payment_terms_text'] ?? $this->settings->get('payment_terms_text')),
            'closing_text'       => (string) ($input['closing_text'] ?? $this->settings->get('closing_text')),
            'net_total_cents'    => $totals->netTotalCents,
            'tax_total_cents'    => $totals->taxTotalCents,
            'gross_total_cents'  => $totals->grossTotalCents,
            'tax_breakdown'      => $this->encode($totals->breakdownToArray()),
        ];

        return Db::transaction(function () use ($id, $row, $itemsInput, $lineItems, $totals, $userId): int {
            if ($id === null) {
                $row['status']            = 'draft';
                $row['doc_type']          = 'invoice';
                $row['customer_snapshot'] = $this->encode([]);
                $row['seller_snapshot']   = $this->encode([]);
                $row['created_by']        = $userId;
                $id = $this->invoices->insert($row);
            } else {
                $this->invoices->update($id, $row);
            }

            $this->invoices->replaceItems($id, $this->persistableItems($itemsInput, $lineItems, $totals));

            return $id;
        });
    }

    /** @return array<string,mixed> das fertige Rechnungs-Array laut Datenvertrag */
    public function finalize(int $id): array
    {
        Db::transaction(function (PDO $pdo) use ($id): void {
            $row = $this->invoices->findRaw($id);

            if ($row === null) {
                throw new DomainException("Rechnung {$id} existiert nicht.");
            }
            if ($row['status'] !== 'draft') {
                throw new DomainException('Nur Entwuerfe koennen finalisiert werden.');
            }
            if ($this->invoices->itemsRaw($id) === []) {
                throw new DomainException('Eine Rechnung ohne Positionen kann nicht finalisiert werden.');
            }

            $customerRow = $row['customer_id'] !== null ? $this->customers->find((int) $row['customer_id']) : null;
            if ($customerRow === null) {
                throw new DomainException('Ohne Kunde keine Rechnung.');
            }

            $year   = (int) substr((string) $row['invoice_date'], 0, 4);
            $number = $this->numberGenerator()->allocate($pdo, $year);

            $this->invoices->update($id, [
                'status'            => 'final',
                'number'            => $number['number'],
                'number_seq'        => $number['seq'],
                'number_year'       => $number['year'],
                'customer_snapshot' => $this->encode($this->customers->snapshot($customerRow)),
                'seller_snapshot'   => $this->encode($this->settings->seller()),
                'finalized_at'      => date('Y-m-d H:i:s'),
            ]);
        });

        $invoice = $this->invoices->load($id);
        if ($invoice === null) {
            throw new DomainException("Rechnung {$id} ist nach dem Finalisieren nicht lesbar.");
        }

        return $invoice;
    }

    /**
     * Erzeugt eine Storno-Rechnung mit eigener Nummer und setzt das Original
     * auf "cancelled". Die Betraege bleiben positiv; dass es sich um eine
     * Gutschrift handelt, steckt im doc_type (ZUGFeRD-Typcode 381).
     */
    public function cancel(int $id, string $reason): int
    {
        return Db::transaction(function (PDO $pdo) use ($id, $reason): int {
            $original = $this->invoices->findRaw($id);

            if ($original === null) {
                throw new DomainException("Rechnung {$id} existiert nicht.");
            }
            if ($original['status'] !== 'final') {
                throw new DomainException('Nur finalisierte Rechnungen koennen storniert werden.');
            }

            $year   = (int) date('Y');
            $number = $this->numberGenerator()->allocate($pdo, $year);

            $stornoId = $this->invoices->insert([
                'status'             => 'final',
                'doc_type'           => 'credit_note',
                'number'             => $number['number'],
                'number_seq'         => $number['seq'],
                'number_year'        => $number['year'],
                'invoice_date'       => date('Y-m-d'),
                'service_from'       => $original['service_from'],
                'service_to'         => $original['service_to'],
                'subject'            => $original['subject'],
                'customer_id'        => $original['customer_id'],
                'customer_snapshot'  => $original['customer_snapshot'],
                'seller_snapshot'    => $original['seller_snapshot'],
                'payment_terms_text' => $original['payment_terms_text'],
                'closing_text'       => $original['closing_text'],
                'net_total_cents'    => $original['net_total_cents'],
                'tax_total_cents'    => $original['tax_total_cents'],
                'gross_total_cents'  => $original['gross_total_cents'],
                'tax_breakdown'      => $original['tax_breakdown'],
                'cancels_invoice_id' => $id,
                'cancel_reason'      => $reason,
                'created_by'         => $original['created_by'],
                'finalized_at'       => date('Y-m-d H:i:s'),
            ]);

            $items = array_map(static function (array $row): array {
                unset($row['id'], $row['invoice_id'], $row['sort_order']);

                return $row;
            }, $this->invoices->itemsRaw($id));

            $this->invoices->replaceItems($stornoId, $items);
            $this->invoices->update($id, ['status' => 'cancelled']);

            return $stornoId;
        });
    }

    private function numberGenerator(): NumberGenerator
    {
        return new NumberGenerator(
            $this->settings->get('number_format', '{seq}/{year}'),
            $this->settings->get('number_reset_yearly', '1') === '1',
        );
    }

    private function toLineItem(array $raw): LineItem
    {
        $kind     = (string) ($raw['kind'] ?? LineItem::KIND_CUSTOM);
        $category = (string) ($raw['tax_category'] ?? TaxCategory::STANDARD);
        $rateBp   = $category === TaxCategory::STANDARD ? (int) ($raw['tax_rate_bp'] ?? 1900) : 0;

        $beds     = $this->intOrNull($raw['beds'] ?? null);
        $dateFrom = $this->nullIfBlank($raw['date_from'] ?? null);
        $dateTo   = $this->nullIfBlank($raw['date_to'] ?? null);

        // Bei Uebernachtungen ergibt sich die Menge aus Betten mal Naechten,
        // damit Eingabe und Rechnung nicht auseinanderlaufen koennen.
        if ($kind === LineItem::KIND_ACCOMMODATION && $beds !== null && $dateFrom !== null && $dateTo !== null) {
            $nights   = LineItem::nightsBetween($dateFrom, $dateTo);
            $quantity = (float) ($beds * $nights);
        } else {
            $nights   = $this->intOrNull($raw['nights'] ?? null);
            $quantity = (float) ($raw['quantity'] ?? 1);
        }

        // Bei "total" gibt die Zeilensumme den Ton an; der Einzelpreis wird
        // daraus abgeleitet und nicht aus der Eingabe uebernommen.
        $priceMode = ($raw['price_mode'] ?? LineItem::MODE_UNIT) === LineItem::MODE_TOTAL
            ? LineItem::MODE_TOTAL
            : LineItem::MODE_UNIT;

        return new LineItem(
            kind: $kind,
            description: trim((string) ($raw['description'] ?? '')),
            quantity: $quantity,
            unitPriceGrossCents: (int) ($raw['unit_price_gross_cents'] ?? 0),
            taxRateBp: $rateBp,
            taxCategory: $category,
            taxExemptionReason: $this->nullIfBlank($raw['tax_exemption_reason'] ?? null),
            apartmentName: $this->nullIfBlank($raw['apartment_name'] ?? null),
            beds: $beds,
            nights: $nights,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            priceMode: $priceMode,
            lineTotalGrossCents: $priceMode === LineItem::MODE_TOTAL
                ? (int) ($raw['line_gross_cents'] ?? 0)
                : null,
        );
    }

    /**
     * @param  array<int,array<string,mixed>> $itemsInput
     * @param  LineItem[]                     $lineItems
     * @return array<int,array<string,mixed>>
     */
    private function persistableItems(array $itemsInput, array $lineItems, Totals $totals): array
    {
        $out = [];

        foreach ($lineItems as $i => $item) {
            $out[] = [
                'kind'                   => $item->kind,
                'description'            => $item->description,
                'apartment_id'           => $this->intOrNull($itemsInput[$i]['apartment_id'] ?? null),
                'apartment_name'         => $item->apartmentName,
                'date_from'              => $item->dateFrom,
                'date_to'                => $item->dateTo,
                'beds'                   => $item->beds,
                'nights'                 => $item->nights,
                'quantity'               => $item->quantity,
                // Bei Modus "total" wird der Einzelpreis gerundet abgelegt.
                // Massgeblich bleibt line_gross_cents - die Anzeige rechnet
                // den genauen Wert jederzeit daraus zurueck.
                'unit_price_gross_cents' => (int) round($item->unitPriceCents()),
                'price_mode'             => $item->priceMode,
                'tax_rate_bp'            => $item->taxRateBp,
                'tax_category'           => $item->taxCategory,
                'tax_exemption_reason'   => $item->taxExemptionReason,
                'line_gross_cents'       => $totals->lineGrossCents[$i],
                'line_net_cents'         => $totals->lineNetCents[$i],
            ];
        }

        return $out;
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace Pmz\Pdf;

use DateTimeImmutable;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use Pmz\Domain\TaxCategory;

/**
 * Baut das ZUGFeRD-XML im Profil EN16931 aus dem Rechnungs-Array.
 *
 * Betraege gehen als Euro mit zwei Nachkommastellen raus; intern rechnet das
 * Projekt in Cent. Stornos tragen positive Betraege und unterscheiden sich nur
 * durch den Typcode 381 - so legt es InvoiceService::cancel() an.
 */
final class ZugferdBuilder
{
    private const TAX_TYPE_VAT = 'VAT';

    /** UN/ECE Rec 20: C62 = "one"/Stueck. Betten mal Naechte sind unbenannte Einheiten. */
    private const UNIT_PIECE = 'C62';

    public function buildXml(array $invoice): string
    {
        $seller   = $invoice['seller'];
        $customer = $invoice['customer'];

        $builder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_EN16931);

        $builder->setDocumentInformation(
            (string) ($invoice['number'] ?? $invoice['id']),
            $invoice['doc_type'] === 'credit_note' ? '381' : '380',
            new DateTimeImmutable($invoice['invoice_date']),
            'EUR',
        );

        if ($invoice['doc_type'] === 'credit_note' && ($invoice['cancel_reason'] ?? '') !== '') {
            $builder->addDocumentNote('Stornogrund: ' . $invoice['cancel_reason']);
        }

        $this->addSeller($builder, $seller);
        $this->addBuyer($builder, $customer);
        $this->addDates($builder, $invoice);
        $this->addPositions($builder, $invoice['items']);
        $this->addTaxes($builder, $invoice['tax_breakdown']);
        $this->addPayment($builder, $invoice, $seller);

        $builder->setDocumentSummation(
            self::euro($invoice['gross_total_cents']),
            self::euro($invoice['gross_total_cents']),
            self::euro($invoice['net_total_cents']),
            0.0,
            0.0,
            self::euro($invoice['net_total_cents']),
            self::euro($invoice['tax_total_cents']),
        );

        return $builder->getContent();
    }

    private function addSeller(ZugferdDocumentBuilder $builder, array $seller): void
    {
        $builder->setDocumentSeller((string) $seller['name']);
        $builder->setDocumentSellerAddress(
            (string) $seller['street'],
            null,
            null,
            (string) $seller['postcode'],
            (string) $seller['city'],
            (string) ($seller['country_code'] !== '' ? $seller['country_code'] : 'DE'),
        );
        $builder->setDocumentSellerContact(
            (string) $seller['owner'],
            null,
            (string) $seller['phone'],
            null,
            (string) $seller['email'],
        );

        // VA = USt-IdNr, FC = nationale Steuernummer. Ein leerer Eintrag wuerde
        // eine leere Registrierung ins XML schreiben und EN16931 brechen.
        if ((string) $seller['vat_id'] !== '') {
            $builder->addDocumentSellerTaxRegistration('VA', (string) $seller['vat_id']);
        }
        if ((string) $seller['tax_number'] !== '') {
            $builder->addDocumentSellerTaxRegistration('FC', (string) $seller['tax_number']);
        }
    }

    private function addBuyer(ZugferdDocumentBuilder $builder, array $customer): void
    {
        $name = (string) $customer['company'] !== ''
            ? (string) $customer['company']
            : (string) $customer['contact_name'];

        $builder->setDocumentBuyer($name);
        $builder->setDocumentBuyerAddress(
            (string) $customer['street'],
            null,
            null,
            (string) $customer['postcode'],
            (string) $customer['city'],
            (string) ($customer['country_code'] !== '' ? $customer['country_code'] : 'DE'),
        );

        if ((string) $customer['company'] !== '' && (string) $customer['contact_name'] !== '') {
            $builder->setDocumentBuyerContact(
                (string) $customer['contact_name'],
                null,
                (string) $customer['phone'],
                null,
                (string) $customer['email'],
            );
        }

        if ((string) $customer['vat_id'] !== '') {
            $builder->addDocumentBuyerTaxRegistration('VA', (string) $customer['vat_id']);
        }
    }

    private function addDates(ZugferdDocumentBuilder $builder, array $invoice): void
    {
        $from = $invoice['service_from'];
        $to   = $invoice['service_to'];

        // Lieferdatum: das Ende des Leistungszeitraums, sonst dessen Beginn,
        // sonst das Rechnungsdatum - EN16931 will hier ein einzelnes Datum.
        $builder->setDocumentSupplyChainEvent(
            new DateTimeImmutable($to ?? $from ?? $invoice['invoice_date'])
        );

        if ($from === null && $to === null) {
            return;
        }

        $builder->setDocumentBillingPeriod(
            $from !== null ? new DateTimeImmutable($from) : null,
            $to !== null ? new DateTimeImmutable($to) : null,
            null,
        );
    }

    private function addPositions(ZugferdDocumentBuilder $builder, array $items): void
    {
        foreach (array_values($items) as $position => $item) {
            $builder->addNewPosition((string) ($position + 1));
            $builder->setDocumentPositionProductDetails($item['description']);

            $builder->setDocumentPositionGrossPrice(self::euro($item['unit_price_gross_cents']));
            $builder->setDocumentPositionNetPrice(self::euro($this->unitNetCents($item)));
            $builder->setDocumentPositionQuantity($item['quantity'], self::UNIT_PIECE);

            $builder->addDocumentPositionTax(
                $item['tax_category'],
                self::TAX_TYPE_VAT,
                $this->percent($item['tax_category'], $item['tax_rate_bp']),
                null,
                $item['tax_exemption_reason'],
            );

            $builder->setDocumentPositionLineSummation(self::euro($item['line_net_cents']));

            if ($item['date_from'] !== null || $item['date_to'] !== null) {
                $builder->setDocumentPositionSupplyChainEvent(
                    new DateTimeImmutable($item['date_to'] ?? $item['date_from'])
                );
            }
        }
    }

    private function addTaxes(ZugferdDocumentBuilder $builder, array $breakdown): void
    {
        foreach ($breakdown as $group) {
            $builder->addDocumentTax(
                $group['category'],
                self::TAX_TYPE_VAT,
                self::euro($group['net_cents']),
                self::euro($group['tax_cents']),
                $this->percent($group['category'], $group['rate_bp']),
                $group['exemption_reason'],
            );
        }
    }

    private function addPayment(ZugferdDocumentBuilder $builder, array $invoice, array $seller): void
    {
        if ($invoice['payment_terms_text'] !== '') {
            $builder->addDocumentPaymentTerm($invoice['payment_terms_text']);
        }

        // Die IBAN steht in den Stammdaten mit Leerzeichen; BT-84 will sie ohne.
        $iban = str_replace(' ', '', (string) $seller['bank_iban']);
        if ($iban === '') {
            return;
        }

        $bic = str_replace(' ', '', (string) $seller['bank_bic']);

        $builder->addDocumentPaymentMeanToCreditTransfer(
            $iban,
            (string) $seller['name'],
            null,
            $bic !== '' ? $bic : null,
        );
    }

    /**
     * Nettopreis je Einheit. EN16931 kennt kein Cent, deshalb wird hier gerundet;
     * massgeblich fuer die Summenpruefung bleibt das ungerundete line_net_cents.
     */
    private function unitNetCents(array $item): int
    {
        $quantity = (float) $item['quantity'];

        if ($quantity === 0.0) {
            return $item['line_net_cents'];
        }

        return (int) round($item['line_net_cents'] / $quantity);
    }

    /** Nur die Regelbesteuerung traegt einen Satz; E, Z und O sind per Definition 0 %. */
    private function percent(string $category, int $rateBp): float
    {
        return $category === TaxCategory::STANDARD ? round($rateBp / 100, 2) : 0.0;
    }

    private static function euro(int $cents): float
    {
        return round($cents / 100, 2);
    }
}

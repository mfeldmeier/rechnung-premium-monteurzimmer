<?php

declare(strict_types=1);

namespace Pmz\Service;

use Pmz\Domain\Booking;
use Pmz\Domain\LineItem;
use Pmz\Domain\TaxCategory;
use Pmz\Repo\ApartmentRepo;
use Pmz\Repo\InvoiceRepo;
use Pmz\Repo\SettingsRepo;

/**
 * Macht aus einer gelesenen Buchung einen Rechnungsentwurf.
 *
 * Gerechnet wird nichts eigenes: der Entwurf geht durch denselben
 * InvoiceService::saveDraft() wie eine von Hand getippte Rechnung, damit
 * Rundung und Steuergruppen an genau einer Stelle leben.
 */
final class BookingImporter
{
    public function __construct(
        private readonly InvoiceService $invoices = new InvoiceService(),
        private readonly InvoiceRepo $repo = new InvoiceRepo(),
        private readonly ApartmentRepo $apartments = new ApartmentRepo(),
        private readonly SettingsRepo $settings = new SettingsRepo(),
    ) {
    }

    /**
     * @return array{invoiceId:int,created:bool,warnings:list<string>}
     *         created=false heisst: die Buchung gab es schon, nichts angelegt.
     */
    public function import(Booking $booking): array
    {
        $existing = $this->repo->findIdByBookingRef($booking->bookingRef);

        if ($existing !== null) {
            // Dieselbe Mail kann mehrfach ankommen. Ein zweiter Entwurf waere
            // schlimmer als keiner - stillschweigend neu anlegen erst recht.
            return ['invoiceId' => $existing, 'created' => false, 'warnings' => []];
        }

        $warnings  = [];
        $apartment = $booking->objectNo !== null
            ? $this->apartments->findByExternalRef($booking->objectNo)
            : null;

        if ($apartment === null) {
            // Kein Abbruch: der Entwurf ist auch ohne Wohnung die halbe Arbeit,
            // und die Zuordnung ist in zwei Klicks nachgeholt.
            $warnings[] = sprintf(
                'Zur Objektnummer %s ist keine Wohnung hinterlegt. Der Entwurf hat deshalb keine Wohnung - '
                . 'unter "Wohnungen" die externe Objektnummer eintragen, dann klappt es beim nächsten Mal.',
                $booking->objectNo ?? '(fehlt)',
            );
        }

        $invoiceId = $this->invoices->saveDraft(null, $this->toInput($booking, $apartment));

        return ['invoiceId' => $invoiceId, 'created' => true, 'warnings' => $warnings];
    }

    /**
     * @param  array<string,mixed>|null $apartment
     * @return array<string,mixed>
     */
    private function toInput(Booking $booking, ?array $apartment): array
    {
        return [
            'invoice_date'       => date('Y-m-d'),
            'service_from'       => $booking->arrival,
            'service_to'         => $booking->departure,
            'subject'            => $this->subject($booking),
            'customer_id'        => null,
            // Der Gast ist fast immer einmalig. Ihn als Stammkunden anzulegen
            // hiesse, den Kundenstamm mit Karteileichen zu fuellen.
            'customer_freeform'  => $booking->recipientText(),
            'booking_ref'        => $booking->bookingRef,
            'payment_terms_text' => $this->settings->get('payment_terms_text'),
            'closing_text'       => $this->settings->get('closing_text'),
            'prices_include_tax' => true,
            'items'              => $this->items($booking, $apartment),
        ];
    }

    /**
     * @param  array<string,mixed>|null $apartment
     * @return array<int,array<string,mixed>>
     */
    private function items(Booking $booking, ?array $apartment): array
    {
        $name  = $apartment !== null ? (string) $apartment['name'] : ($booking->objectName ?? 'Unterkunft');
        $items = [];

        $items[] = [
            'kind'                   => LineItem::KIND_ACCOMMODATION,
            'description'            => 'Wohnung ' . $name,
            'apartment_id'           => $apartment !== null ? (int) $apartment['id'] : null,
            'apartment_name'         => $name,
            'date_from'              => $booking->arrival,
            'date_to'                => $booking->departure,
            'beds'                   => $booking->guests,
            'nights'                 => $booking->nights,
            'quantity'               => $booking->quantity(),
            // Secra nennt einen Endpreis inklusive Portalaufschlag. Der muss
            // auf den Cent stehen bleiben, der Nachtpreis ergibt sich daraus.
            'price_mode'             => LineItem::MODE_TOTAL,
            'unit_price_gross_cents' => 0,
            'line_gross_cents'       => $booking->rentCents,
            'tax_rate_bp'            => 700,
            'tax_category'           => TaxCategory::STANDARD,
            'tax_exemption_reason'   => null,
        ];

        if ($booking->cleaningCents > 0) {
            $items[] = [
                'kind'                   => LineItem::KIND_CLEANING,
                'description'            => 'Endreinigung',
                'quantity'               => 1.0,
                'price_mode'             => LineItem::MODE_UNIT,
                'unit_price_gross_cents' => $booking->cleaningCents,
                'line_gross_cents'       => $booking->cleaningCents,
                'tax_rate_bp'            => 1900,
                'tax_category'           => TaxCategory::STANDARD,
                'tax_exemption_reason'   => null,
            ];
        }

        if ($booking->depositCents > 0) {
            $items[] = [
                'kind'                   => LineItem::KIND_DEPOSIT,
                'description'            => 'Kaution',
                'quantity'               => 1.0,
                'price_mode'             => LineItem::MODE_UNIT,
                'unit_price_gross_cents' => $booking->depositCents,
                'line_gross_cents'       => $booking->depositCents,
                'tax_rate_bp'            => 0,
                'tax_category'           => TaxCategory::EXEMPT,
                'tax_exemption_reason'   => 'Kaution – durchlaufender Posten, kein Entgelt i.S.d. § 10 UStG',
            ];
        }

        return $items;
    }

    private function subject(Booking $booking): string
    {
        $vorlage = trim((string) $this->settings->get('invoice_subject'));

        return $vorlage !== ''
            ? $vorlage
            : sprintf(
                'Unterbringung vom %s bis %s',
                $this->german($booking->arrival),
                $this->german($booking->departure),
            );
    }

    private function german(string $isoDate): string
    {
        return date('d.m.Y', (int) strtotime($isoDate));
    }
}

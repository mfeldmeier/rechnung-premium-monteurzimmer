<?php

declare(strict_types=1);

namespace Pmz\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Eine Rechnungszeile, wie sie im Editor eingegeben wird.
 *
 * Preise werden brutto erfasst - so steht es in der Vorlage ("Preis pro Nacht
 * u. Bett Brutto") und so werden die Naechte verhandelt. Netto und Steuer
 * leitet der Calculator daraus ab, nicht umgekehrt.
 */
final readonly class LineItem
{
    public const KIND_ACCOMMODATION = 'accommodation';
    public const KIND_CLEANING      = 'cleaning';
    public const KIND_DEPOSIT       = 'deposit';
    public const KIND_CUSTOM        = 'custom';

    public function __construct(
        public string $kind,
        public string $description,
        public float $quantity,
        public int $unitPriceGrossCents,
        public int $taxRateBp,
        public string $taxCategory = TaxCategory::STANDARD,
        public ?string $taxExemptionReason = null,
        public ?string $apartmentName = null,
        public ?int $beds = null,
        public ?int $nights = null,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
    ) {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Menge darf nicht negativ sein.');
        }
        if ($unitPriceGrossCents < 0) {
            throw new InvalidArgumentException('Einzelpreis darf nicht negativ sein.');
        }
        if (!TaxCategory::isValid($taxCategory)) {
            throw new InvalidArgumentException("Unbekannte Steuerkategorie: {$taxCategory}");
        }
        if ($taxCategory !== TaxCategory::STANDARD && $taxRateBp !== 0) {
            throw new InvalidArgumentException(
                "Steuerkategorie {$taxCategory} verlangt Satz 0, bekommen: {$taxRateBp}"
            );
        }
        if ($taxCategory === TaxCategory::EXEMPT && ($taxExemptionReason === null || $taxExemptionReason === '')) {
            // EN16931 BR-E-10: zu Kategorie E gehoert zwingend eine Begruendung.
            throw new InvalidArgumentException('Steuerbefreiung ohne Begruendung ist nicht zulaessig.');
        }
    }

    /**
     * Fuer Uebernachtungen: Betten mal Naechte. Die Vorlage schreibt das als
     * "2/22" in eine Spalte, gerechnet wird aber mit dem Produkt.
     */
    public static function accommodation(
        string $apartmentName,
        int $beds,
        string $dateFrom,
        string $dateTo,
        int $unitPriceGrossCents,
        int $taxRateBp = 700,
    ): self {
        $nights = self::nightsBetween($dateFrom, $dateTo);

        return new self(
            kind: self::KIND_ACCOMMODATION,
            description: "Wohnung {$apartmentName}",
            quantity: (float) ($beds * $nights),
            unitPriceGrossCents: $unitPriceGrossCents,
            taxRateBp: $taxRateBp,
            apartmentName: $apartmentName,
            beds: $beds,
            nights: $nights,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );
    }

    public static function nightsBetween(string $from, string $to): int
    {
        $days = (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');

        if ($days < 0) {
            throw new InvalidArgumentException('Abreise liegt vor Anreise.');
        }

        return $days;
    }

    public function grossCents(): int
    {
        return (int) round($this->unitPriceGrossCents * $this->quantity);
    }

    /** Der Satz, der fuer die Steuerberechnung zaehlt. */
    public function effectiveRateBp(): int
    {
        return $this->taxCategory === TaxCategory::STANDARD ? $this->taxRateBp : 0;
    }
}

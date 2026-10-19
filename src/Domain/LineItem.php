<?php

declare(strict_types=1);

namespace Pmz\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Eine Rechnungszeile, wie sie im Editor eingegeben wird.
 *
 * Preise werden im Regelfall brutto erfasst - so werden die Naechte verhandelt,
 * und Netto und Steuer leitet der Calculator daraus ab, nicht umgekehrt.
 *
 * Mit Firmenkunden wird dagegen oft netto verhandelt. Steht $priceIncludesTax
 * auf false, sind $unitPriceGrossCents und $lineTotalGrossCents Nettowerte;
 * grossCents() rechnet dann einmal hoch, und ab da laeuft alles Weitere
 * unveraendert ueber den Bruttobetrag. Der Umschalter gilt fuer die ganze
 * Rechnung, nicht je Zeile.
 */
final readonly class LineItem
{
    public const KIND_ACCOMMODATION = 'accommodation';
    public const KIND_CLEANING      = 'cleaning';
    public const KIND_DEPOSIT       = 'deposit';
    public const KIND_CUSTOM        = 'custom';

    /** Der Einzelpreis ist massgeblich, die Zeilensumme wird daraus berechnet. */
    public const MODE_UNIT = 'unit';

    /**
     * Die Zeilensumme ist massgeblich, der Einzelpreis wird daraus abgeleitet.
     * Fuer von aussen vorgegebene Endpreise - etwa die von Secra inklusive
     * Gebuehren -, die sich nicht glatt auf einen Nachtpreis zurueckrechnen
     * lassen.
     */
    public const MODE_TOTAL = 'total';

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
        public string $priceMode = self::MODE_UNIT,
        public ?int $lineTotalGrossCents = null,
        public bool $priceIncludesTax = true,
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
        if (!in_array($priceMode, [self::MODE_UNIT, self::MODE_TOTAL], true)) {
            throw new InvalidArgumentException("Unbekannter Preismodus: {$priceMode}");
        }
        if ($priceMode === self::MODE_TOTAL) {
            if ($lineTotalGrossCents === null) {
                throw new InvalidArgumentException('Preismodus "total" braucht eine Zeilensumme.');
            }
            if ($lineTotalGrossCents < 0) {
                throw new InvalidArgumentException('Zeilensumme darf nicht negativ sein.');
            }
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
        $entered = $this->priceMode === self::MODE_TOTAL
            ? (int) $this->lineTotalGrossCents
            : (int) round($this->unitPriceGrossCents * $this->quantity);

        if ($this->priceIncludesTax) {
            return $entered;
        }

        // Netto-Eingabe: einmal je Zeile hochrechnen. Der Calculator rundet
        // danach wie gewohnt je Steuergruppe - so bleibt es bei einem einzigen
        // Rundungsschritt pro Zeile, und 100,00 EUR netto bei 19 % ergeben
        // exakt die erwarteten 119,00 EUR.
        return (int) round($entered * (10000 + $this->effectiveRateBp()) / 10000);
    }

    /**
     * Der Einzelpreis in Cent, bei Bedarf mit Nachkommaanteil.
     *
     * Bei Modus "total" ergibt sich aus 1.250,00 EUR auf 44 Einheiten ein Wert
     * von 2840,909... Cent. Gerundet auf zwei Nachkommastellen multipliziert
     * sich das nicht mehr zur Zeilensumme zurueck, deshalb wird hier bewusst
     * nicht gerundet - die Darstellung uebernimmt Format::unitPrice().
     */
    public function unitPriceCents(): float
    {
        if ($this->priceMode === self::MODE_TOTAL) {
            return $this->quantity > 0 ? $this->grossCents() / $this->quantity : 0.0;
        }

        // Der Rueckgabewert ist immer brutto - daran haengen die gespeicherte
        // Spalte unit_price_gross_cents und ZUGFeRD BT-148.
        return $this->priceIncludesTax
            ? (float) $this->unitPriceGrossCents
            : $this->unitPriceGrossCents * (10000 + $this->effectiveRateBp()) / 10000;
    }

    /** Der Satz, der fuer die Steuerberechnung zaehlt. */
    public function effectiveRateBp(): int
    {
        return $this->taxCategory === TaxCategory::STANDARD ? $this->taxRateBp : 0;
    }
}

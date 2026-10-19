<?php

declare(strict_types=1);

namespace Pmz\Domain;

use DateTimeImmutable;
use DomainException;
use Pmz\Support\Format;

/**
 * Eine Buchung, wie sie in der secra-Bestaetigung steht.
 *
 * Bewusst ein eigenes Objekt zwischen PDF und Rechnung: der Parser liefert
 * damit etwas Pruefbares ab, und der Importeur muss keine Rohtexte kennen.
 * Die Pruefungen stehen im Konstruktor, weil eine halb gelesene Buchung
 * schlimmer ist als gar keine - sie wuerde einen falschen Entwurf erzeugen.
 */
final readonly class Booking
{
    /**
     * @param list<string> $recipientLines Anschrift des Gasts, Zeile fuer Zeile
     */
    public function __construct(
        public string $bookingRef,
        public ?string $externalRef,
        public ?string $objectNo,
        public ?string $objectName,
        public string $arrival,
        public string $departure,
        public int $nights,
        public int $guests,
        public array $recipientLines,
        public int $rentCents,
        public int $cleaningCents,
        public int $depositCents,
        public int $totalCents,
        public ?string $guestEmail = null,
        public ?string $guestPhone = null,
    ) {
        if ($bookingRef === '') {
            throw new DomainException('Die Buchungsbestätigung nennt keine Buchungsnummer.');
        }
        if ($recipientLines === []) {
            throw new DomainException('Die Buchungsbestätigung nennt keine Gastanschrift.');
        }
        if ($guests < 1) {
            throw new DomainException("Buchung {$bookingRef}: keine Reisenden angegeben.");
        }

        $gemessen = self::nightsBetween($arrival, $departure);

        if ($gemessen !== $nights) {
            // Beides steht im PDF. Weichen sie voneinander ab, ist entweder das
            // Format anders als gedacht oder das Dokument fehlerhaft - in
            // beiden Faellen darf daraus keine Rechnung entstehen.
            throw new DomainException(
                "Buchung {$bookingRef}: {$nights} Nächte angegeben, aus {$arrival} bis {$departure} ergeben sich {$gemessen}."
            );
        }

        // Die Kaution gehoert nicht zum Reisepreis - sie ist ein durchlaufender
        // Posten und steht im PDF ausserhalb der Leistungstabelle.
        $summe = $rentCents + $cleaningCents;

        if ($summe !== $totalCents) {
            throw new DomainException(sprintf(
                'Buchung %s: Miete (%s) und Endreinigung (%s) ergeben %s, ausgewiesen sind %s.',
                $bookingRef,
                Format::money($rentCents),
                Format::money($cleaningCents),
                Format::money($summe),
                Format::money($totalCents),
            ));
        }
    }

    /** Menge fuer die Uebernachtungszeile: Betten mal Naechte, wie im Rechenkern. */
    public function quantity(): float
    {
        return (float) ($this->guests * $this->nights);
    }

    public function recipientText(): string
    {
        return implode("\n", $this->recipientLines);
    }

    private static function nightsBetween(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
    }
}

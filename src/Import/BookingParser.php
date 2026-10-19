<?php

declare(strict_types=1);

namespace Pmz\Import;

use DateTimeImmutable;
use DomainException;
use Pmz\Domain\Booking;

/**
 * Liest eine secra-Buchungsbestaetigung aus ihrem Text.
 *
 * Bewusst ohne Sprachmodell: das Dokument ist streng aufgebaut (Label, Tab,
 * Wert), und ein fester Parser scheitert bei einer Formataenderung laut,
 * statt still etwas Plausibles zu erfinden. Was hier durchgeht, rechnet der
 * Booking-Konstruktor gegen.
 */
final class BookingParser
{
    /**
     * Landesvorwahl als einziger belastbarer Hinweis aufs Land - die
     * Postleitzahl taugt nicht dafuer, 10312 gibt es in halb Europa.
     */
    private const DIALLING_CODES = [
        '+49'  => null,          // Deutschland, braucht keine eigene Zeile
        '+43'  => 'Österreich',
        '+41'  => 'Schweiz',
        '+385' => 'Kroatien',
        '+386' => 'Slowenien',
        '+420' => 'Tschechien',
        '+48'  => 'Polen',
        '+36'  => 'Ungarn',
        '+40'  => 'Rumänien',
        '+359' => 'Bulgarien',
        '+421' => 'Slowakei',
        '+39'  => 'Italien',
        '+33'  => 'Frankreich',
        '+31'  => 'Niederlande',
        '+32'  => 'Belgien',
        '+352' => 'Luxemburg',
    ];

    public function parse(string $text): Booking
    {
        $text  = str_replace("\r\n", "\n", $text);
        $lines = array_map(static fn (string $l): string => trim($l), explode("\n", $text));

        $bookingRef = $this->match('/neue Buchung\s+(\d+)/u', $text)
            ?? $this->field($lines, 'Buchungsnummer');

        if ($bookingRef === null) {
            throw new DomainException('Das ist keine secra-Buchungsbestätigung: keine Buchungsnummer gefunden.');
        }

        $reisezeit = $this->field($lines, 'Reisezeit');

        if ($reisezeit === null || preg_match('/(\d{2}\.\d{2}\.\d{4})\s+bis\s+(\d{2}\.\d{2}\.\d{4})/u', $reisezeit, $m) !== 1) {
            throw new DomainException("Buchung {$bookingRef}: die Reisezeit ist nicht lesbar.");
        }

        return new Booking(
            bookingRef: $bookingRef,
            externalRef: $this->match('/externen Buchungsnummer\s+(\d+)/u', $text),
            objectNo: $this->match('/Objekt\s*Nr\.\s*(\d+)/u', $text),
            objectName: $this->objectName($lines),
            arrival: $this->isoDate($m[1]),
            departure: $this->isoDate($m[2]),
            nights: (int) ($this->match('/(\d+)\s+Nächte?/u', (string) $this->field($lines, 'Übernachtungen')) ?? '0'),
            guests: (int) ($this->match('/(\d+)/u', (string) $this->field($lines, 'Reisende')) ?? '0'),
            recipientLines: $this->recipientLines($lines),
            rentCents: $this->amountOf($lines, 'Mietpreis') ?? 0,
            // Nicht jede Buchung hat eine Endreinigung; fehlt sie, ist das kein Fehler.
            cleaningCents: $this->amountOf($lines, 'Endreinigung') ?? 0,
            depositCents: $this->money($this->match('/Kaution in Höhe von\s+(EUR\s*[\d.,\-]+)/u', $text) ?? '') ?? 0,
            totalCents: $this->amountOf($lines, 'Reisepreis') ?? 0,
            guestEmail: $this->match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $text),
            guestPhone: $this->guestPhone($lines),
        );
    }

    /**
     * Der Wert hinter "Label:" - im PDF-Text durch Tab oder Leerzeichen
     * getrennt. Ist die Zeile zu lang, setzt secra den Wert eine Zeile
     * tiefer; dann gilt die naechste Zeile, sofern sie nicht selbst ein
     * Label ist.
     */
    private function field(array $lines, string $label): ?string
    {
        foreach ($lines as $i => $line) {
            if (!str_starts_with($line, $label . ':')) {
                continue;
            }

            $value = trim(substr($line, strlen($label) + 1));

            if ($value !== '') {
                return $value;
            }

            $next = trim($lines[$i + 1] ?? '');

            if ($next !== '' && preg_match('/^[A-ZÄÖÜ][\wäöüß\- ]*:\s*$/u', $next) !== 1) {
                return $next;
            }
        }

        return null;
    }

    /**
     * Die Zeile aus der Leistungstabelle: "Mietpreis\tEUR 537,83\t1\tEUR 537,83".
     * Genommen wird der erste Betrag - bei Anzahl 1 ist er derselbe wie der
     * Gesamtpreis am Zeilenende.
     */
    private function amountOf(array $lines, string $label): ?int
    {
        foreach ($lines as $line) {
            if (!str_starts_with($line, $label)) {
                continue;
            }

            $rest = trim(substr($line, strlen($label)));

            if ($rest !== '' && ($cents = $this->money($rest)) !== null) {
                return $cents;
            }
        }

        return null;
    }

    /**
     * "EUR 537,83" und "EUR 60,--" sind beide gueltig; die Striche stehen fuer
     * null Cent. Tausenderpunkte kommen vor, Nachkommastellen nie mehr als zwei.
     */
    private function money(string $text): ?int
    {
        if (preg_match('/EUR\s*([\d.]+)(?:,(\d{2}|-{1,2}))?/u', $text, $m) !== 1) {
            return null;
        }

        $euro = (int) str_replace('.', '', $m[1]);
        $cent = isset($m[2]) && ctype_digit($m[2]) ? (int) $m[2] : 0;

        return $euro * 100 + $cent;
    }

    /** Name der Unterkunft ohne den Werbezusatz hinter dem Doppelpunkt. */
    private function objectName(array $lines): ?string
    {
        $value = $this->field($lines, 'Ihre Unterkunft');

        if ($value === null) {
            return null;
        }

        // "Ferienwohnung Orange 1.OG Kassel: 1-4 Betten | Zentral & Ruhig"
        return trim(explode(':', $value, 2)[0]);
    }

    /**
     * Die Anschrift des Gasts, wie sie unter "Gastdaten:" steht. Telefon und
     * E-Mail gehoeren nicht in den Adressblock und fliegen raus; liegt die
     * Vorwahl im Ausland, kommt stattdessen eine Landeszeile dazu - sonst
     * stuende im ZUGFeRD-XML faelschlich DE.
     *
     * @return list<string>
     */
    private function recipientLines(array $lines): array
    {
        $out     = [];
        $sammeln = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'Gastdaten:')) {
                $sammeln = true;
                $first   = trim(substr($line, strlen('Gastdaten:')));

                if ($first !== '') {
                    $out[] = $first;
                }

                continue;
            }

            if (!$sammeln) {
                continue;
            }

            // Der Block endet beim naechsten Label oder bei der ersten Leerzeile.
            if ($line === '' || preg_match('/^[A-ZÄÖÜ][\wäöüß\- ]*:/u', $line) === 1) {
                break;
            }

            if ($this->isPhone($line) || str_contains($line, '@')) {
                continue;
            }

            $out[] = $line;
        }

        $land = $this->country($lines);

        if ($land !== null) {
            $out[] = $land;
        }

        return $out;
    }

    private function guestPhone(array $lines): ?string
    {
        foreach ($lines as $line) {
            if ($this->isPhone($line)) {
                // secra stellt der Nummer einen Bindestrich voran: "-+385 91 ...".
                return ltrim($line, '- ');
            }
        }

        return null;
    }

    private function isPhone(string $line): bool
    {
        return preg_match('/^-?\+\d[\d\s\/()-]{5,}$/u', $line) === 1;
    }

    private function country(array $lines): ?string
    {
        $phone = $this->guestPhone($lines);

        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        // Laengere Vorwahlen zuerst, sonst faengt "+4" Faelle ab, die zu "+49"
        // oder "+420" gehoeren.
        $codes = self::DIALLING_CODES;
        uksort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($codes as $code => $land) {
            if (str_starts_with($digits, (string) $code)) {
                return $land;
            }
        }

        return null;
    }

    private function isoDate(string $german): string
    {
        $date = DateTimeImmutable::createFromFormat('d.m.Y', $german);

        if ($date === false) {
            throw new DomainException("Unlesbares Datum: {$german}");
        }

        return $date->format('Y-m-d');
    }

    private function match(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $m) === 1 ? ($m[1] ?? $m[0]) : null;
    }
}

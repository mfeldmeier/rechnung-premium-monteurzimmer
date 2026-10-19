<?php

declare(strict_types=1);

namespace Pmz\Support;

use DateTimeImmutable;

final class Format
{
    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }

    public static function euro(int $cents): string
    {
        return self::money($cents) . ' €';
    }

    /**
     * Einzelpreis mit zwei bis vier Nachkommastellen.
     *
     * Wird ein Endpreis vorgegeben, ist der Nachtpreis selten glatt: 1.250,00 EUR
     * auf 44 Einheiten ergeben 28,4091. Mit zwei Nachkommastellen stuende dort
     * 28,41, und 28,41 mal 44 waeren 1.250,04 - wer nachrechnet, fuende vier
     * Cent Abweichung. Mehr Nachkommastellen beim Einzelpreis sind bei
     * Rechnungen zulaessig; nur der Gesamtbetrag muss auf den Cent stimmen.
     */
    public static function unitPrice(float $cents, int $maxDecimals = 4): string
    {
        $text = number_format($cents / 100, $maxDecimals, ',', '.');

        if (!str_contains($text, ',')) {
            return $text;
        }

        // Nullen am Ende weg, aber nie unter zwei Nachkommastellen.
        [$whole, $fraction] = explode(',', $text, 2);
        $fraction = rtrim($fraction, '0');

        return $whole . ',' . str_pad($fraction, 2, '0');
    }

    /** Basispunkte als Prozenttext: 700 -> "7", 750 -> "7,5". */
    public static function percent(int $rateBp): string
    {
        $text = number_format($rateBp / 100, 2, ',', '');

        return str_contains($text, ',') ? rtrim(rtrim($text, '0'), ',') : $text;
    }

    /** Wie in der Vorlage: ohne fuehrende Nullen, "27.9.2026". */
    public static function date(string $isoDate): string
    {
        $d = new DateTimeImmutable($isoDate);

        return (int) $d->format('j') . '.' . (int) $d->format('n') . '.' . $d->format('Y');
    }

    /** "9.10.-31.10.2026", Jahr nur einmal wenn beide Daten im selben Jahr liegen. */
    public static function dateRange(string $isoFrom, string $isoTo): string
    {
        $from = new DateTimeImmutable($isoFrom);
        $to   = new DateTimeImmutable($isoTo);

        if ($from->format('Y') === $to->format('Y')) {
            return (int) $from->format('j') . '.' . (int) $from->format('n') . '.-' . self::date($isoTo);
        }

        return self::date($isoFrom) . '-' . self::date($isoTo);
    }
}

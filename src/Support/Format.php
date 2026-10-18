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

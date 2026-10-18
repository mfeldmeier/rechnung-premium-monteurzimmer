<?php

declare(strict_types=1);

namespace Pmz\Domain;

/**
 * UNTDID-5305-Codes, so wie EN16931 / ZUGFeRD sie erwarten.
 *
 * Zur Kaution: fachlich ist sie ein durchlaufender Posten und damit gar kein
 * Entgelt. Der dafuer eigentlich passende Code waere O ("not subject to VAT") -
 * den verbietet EN16931 aber in Rechnungen, die daneben noch besteuerte
 * Positionen enthalten (BR-O-11). Deshalb wird die Kaution als E mit
 * Befreiungsgrund gefuehrt. Das ist die uebliche Behelfsloesung; ob sie dem
 * Steuerberater reicht, gehoert einmal geprueft.
 */
final class TaxCategory
{
    public const STANDARD = 'S';   // Regelbesteuerung (7 % / 19 %)
    public const EXEMPT   = 'E';   // steuerbefreit, Begruendung Pflicht
    public const ZERO     = 'Z';   // Nullsatz
    public const OUTSIDE  = 'O';   // nicht steuerbar, nur allein verwendbar

    public static function isValid(string $code): bool
    {
        return in_array($code, [self::STANDARD, self::EXEMPT, self::ZERO, self::OUTSIDE], true);
    }
}

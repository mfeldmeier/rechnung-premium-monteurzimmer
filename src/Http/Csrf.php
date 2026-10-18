<?php

declare(strict_types=1);

namespace Pmz\Http;

use RuntimeException;

final class Csrf
{
    public const FIELD = '_csrf';

    public static function token(): string
    {
        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']) || $_SESSION['csrf'] === '') {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            self::FIELD,
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8'),
        );
    }

    public static function check(Request $r): void
    {
        $expected = $_SESSION['csrf'] ?? null;
        $given    = $r->post(self::FIELD) ?? $r->header('X-CSRF-Token');

        // hash_equals statt === : der Vergleich bricht nicht beim ersten
        // abweichenden Zeichen ab und verraet darueber keine Teiltreffer.
        if (!is_string($expected) || $expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            throw new RuntimeException('CSRF-Pruefung fehlgeschlagen.');
        }
    }
}

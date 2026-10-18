<?php

declare(strict_types=1);

use Pmz\Http\Csrf;
use Pmz\Support\Format;

if (!function_exists('e')) {
    /** Jede dynamische Ausgabe laeuft hier durch. */
    function e(mixed $value): string
    {
        return htmlspecialchars($value === null ? '' : (string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('eur')) {
    function eur(int $cents): string
    {
        return Format::euro($cents);
    }
}

if (!function_exists('pct')) {
    function pct(int $rateBp): string
    {
        return Format::percent($rateBp) . ' %';
    }
}

if (!function_exists('dmy')) {
    function dmy(?string $isoDate): string
    {
        return ($isoDate === null || $isoDate === '') ? '' : Format::date($isoDate);
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('urlWith')) {
    /**
     * Baut einen Link und behaelt die uebergebenen Filter bei. Leere Werte
     * fallen raus, damit die Adresszeile lesbar bleibt.
     *
     * @param array<string,string|int> $params
     */
    function urlWith(string $path, array $params): string
    {
        $params = array_filter(
            $params,
            static fn (string|int $v): bool => $v !== '' && $v !== 0,
        );

        return $params === [] ? $path : $path . '?' . http_build_query($params);
    }
}

if (!function_exists('statusLabel')) {
    function statusLabel(string $status): string
    {
        return match ($status) {
            'draft'     => 'Entwurf',
            'final'     => 'Final',
            'cancelled' => 'Storniert',
            default     => $status,
        };
    }
}

if (!function_exists('customerLine')) {
    /** @param array<string,mixed> $customer */
    function customerLine(array $customer): string
    {
        $parts = array_filter([
            (string) ($customer['company'] ?? ''),
            (string) ($customer['contact_name'] ?? ''),
        ], static fn (string $v): bool => $v !== '');

        return $parts === [] ? '–' : implode(', ', $parts);
    }
}

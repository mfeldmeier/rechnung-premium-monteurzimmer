<?php

declare(strict_types=1);

namespace Pmz\Support;

use RuntimeException;

final class Env
{
    /** @var array<string,string>|null */
    private static ?array $values = null;

    public static function load(string $path): void
    {
        $values = [];

        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $values[trim($parts[0])] = trim($parts[1], " \t\"'");
            }
        }

        // Echte Umgebungsvariablen gewinnen, damit die GitHub Action und der
        // Server ohne .env-Datei auskommen koennen.
        foreach (array_keys($values) as $key) {
            $fromEnv = getenv($key);
            if ($fromEnv !== false && $fromEnv !== '') {
                $values[$key] = $fromEnv;
            }
        }

        self::$values = $values;
    }

    public static function get(string $key, ?string $default = null): string
    {
        if (self::$values === null) {
            throw new RuntimeException('Env::load() wurde nicht aufgerufen.');
        }

        $value = self::$values[$key] ?? null;

        if ($value === null || $value === '') {
            $fromEnv = getenv($key);
            $value = $fromEnv === false ? null : $fromEnv;
        }

        if ($value === null || $value === '') {
            if ($default === null) {
                throw new RuntimeException("Pflicht-Konfiguration fehlt: {$key}");
            }

            return $default;
        }

        return $value;
    }
}

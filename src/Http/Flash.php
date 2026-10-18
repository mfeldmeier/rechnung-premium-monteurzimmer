<?php

declare(strict_types=1);

namespace Pmz\Http;

use Pmz\Auth\Session;

/**
 * Einmalmeldungen ueber einen Redirect hinweg (PRG-Muster). Ohne sie muesste
 * jede erfolgreiche POST-Aktion ihre Seite selbst rendern und waere per
 * Reload erneut absendbar.
 */
final class Flash
{
    public const SUCCESS = 'success';
    public const ERROR   = 'error';

    public static function add(string $type, string $message): void
    {
        Session::start();

        $queue = $_SESSION['flash'] ?? [];
        if (!is_array($queue)) {
            $queue = [];
        }

        $queue[] = ['type' => $type, 'message' => $message];

        $_SESSION['flash'] = $queue;
    }

    public static function success(string $message): void
    {
        self::add(self::SUCCESS, $message);
    }

    public static function error(string $message): void
    {
        self::add(self::ERROR, $message);
    }

    /** @return list<array{type:string,message:string}> */
    public static function pull(): array
    {
        Session::start();

        $queue = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        if (!is_array($queue)) {
            return [];
        }

        $out = [];
        foreach ($queue as $entry) {
            if (is_array($entry) && isset($entry['type'], $entry['message'])) {
                $out[] = ['type' => (string) $entry['type'], 'message' => (string) $entry['message']];
            }
        }

        return $out;
    }
}

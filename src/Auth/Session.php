<?php

declare(strict_types=1);

namespace Pmz\Auth;

final class Session
{
    private const NAME = 'pmzsess';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(self::NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            // Lax statt Strict: beim Klick aus einer E-Mail heraus soll die
            // Sitzung erhalten bleiben. Schreibende Aktionen sind ohnehin
            // POST plus CSRF-Token abgesichert.
            'samesite' => 'Lax',
            // secure nur setzen, wenn die Verbindung wirklich HTTPS ist -
            // sonst setzt PHP lokal ueber http gar kein Cookie mehr.
            'secure'   => self::isHttps(),
        ]);

        session_start();
    }

    /** @param array<string,mixed> $user */
    public static function login(array $user): void
    {
        self::start();

        // Gegen Session Fixation: die vor dem Login bekannte ID wird ungueltig.
        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'    => (int) ($user['id'] ?? 0),
            'email' => (string) ($user['email'] ?? ''),
            'name'  => (string) ($user['name'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function user(): ?array
    {
        self::start();

        $user = $_SESSION['user'] ?? null;

        return is_array($user) && ($user['id'] ?? 0) > 0 ? $user : null;
    }

    public static function isLoggedIn(): bool
    {
        return self::user() !== null;
    }

    public static function logout(): void
    {
        self::start();

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }

    private static function isHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

        return is_string($proto) && strtolower($proto) === 'https';
    }
}

<?php

declare(strict_types=1);

namespace Pmz\Auth;

use PDO;
use Pmz\Http\RedirectException;
use Pmz\Support\Db;

final class Authenticator
{
    public const LOGIN_PATH = '/login';

    private const WINDOW_MINUTES = 15;
    private const MAX_FAILURES   = 10;

    /**
     * Fester Hash fuer den Leerlauf-Vergleich. Ohne ihn waere eine Anfrage mit
     * unbekannter E-Mail messbar schneller als eine mit bekannter - damit liesse
     * sich von aussen herausfinden, welche Konten existieren.
     */
    private const DUMMY_HASH = '$2y$10$fYG/PhRggiEByh.HII9w3.Agazx2/n.Z9pkhUmwVb7m/JcqWYhpGC';

    /**
     * @return array<string,mixed>|null Nutzerdatensatz bei Erfolg, sonst null.
     */
    public static function attempt(string $email, string $password): ?array
    {
        // Immer normalisieren: wer sich als "Max@Firma.de" anlegt, meldet sich
        // spaeter mit "max@firma.de" an und muss denselben Datensatz treffen.
        $email = strtolower(trim($email));

        if ($email === '' || self::isLocked($email)) {
            return null;
        }

        $user = self::findByEmail($email);

        $hash = is_array($user) && is_string($user['password_hash'] ?? null)
            ? $user['password_hash']
            : self::DUMMY_HASH;

        $ok = password_verify($password, $hash);

        if ($user === null || (int) ($user['is_active'] ?? 0) !== 1) {
            // Der Dummy-Vergleich oben ist bereits gelaufen, die Laufzeit
            // unterscheidet sich also nicht von einem echten Fehlversuch.
            $ok = false;
        }

        self::log($email, $ok);

        if (!$ok || $user === null) {
            return null;
        }

        self::onSuccess((int) $user['id'], $email, $password, (string) $user['password_hash']);

        return $user;
    }

    /**
     * Zaehlt Fehlversuche pro E-Mail, NICHT pro IP: hinter dem Reverse Proxy
     * sehen wir fuer alle Nutzer dieselbe Adresse, eine IP-Sperre wuerde also
     * bei einem einzigen Angreifer sofort alle Konten mit aussperren.
     */
    public static function isLocked(string $email): bool
    {
        $email = strtolower(trim($email));

        // Die Minuten stehen als Konstante direkt im SQL: MySQL erlaubt in
        // INTERVAL keinen Platzhalter, wenn Emulation abgeschaltet ist.
        $sql = 'SELECT COUNT(*) FROM ' . Db::table('login_attempts')
            . ' WHERE email = :email AND succeeded = 0'
            . ' AND created_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)';

        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([':email' => $email]);

        return (int) $stmt->fetchColumn() >= self::MAX_FAILURES;
    }

    /** @return array<string,mixed> */
    public static function requireLogin(): array
    {
        $user = Session::user();

        if ($user === null) {
            throw new RedirectException(self::LOGIN_PATH);
        }

        return $user;
    }

    /** @return array<string,mixed>|null */
    private static function findByEmail(string $email): ?array
    {
        $stmt = Db::pdo()->prepare(
            'SELECT id, email, name, password_hash, is_active FROM ' . Db::table('users') . ' WHERE email = :email',
        );
        $stmt->execute([':email' => $email]);

        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private static function log(string $email, bool $succeeded): void
    {
        $stmt = Db::pdo()->prepare(
            'INSERT INTO ' . Db::table('login_attempts') . ' (email, succeeded) VALUES (:email, :ok)',
        );
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':ok', $succeeded ? 1 : 0, PDO::PARAM_INT);
        $stmt->execute();
    }

    private static function onSuccess(int $userId, string $email, string $password, string $currentHash): void
    {
        $pdo = Db::pdo();

        $pdo->prepare('UPDATE ' . Db::table('users') . ' SET last_login_at = NOW() WHERE id = :id')
            ->execute([':id' => $userId]);

        // Faengt den Fall ab, dass PHP spaeter einen staerkeren Default-Algorithmus
        // oder hoehere Kosten mitbringt - das Klartext-Passwort liegt nur hier vor.
        if (password_needs_rehash($currentHash, PASSWORD_DEFAULT)) {
            $pdo->prepare('UPDATE ' . Db::table('users') . ' SET password_hash = :hash WHERE id = :id')
                ->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $userId]);
        }

        // Alte Fehlversuche loeschen, sonst steht ein Nutzer nach mehreren
        // Tippfehlern bis zum Ende des Zeitfensters kurz vor der Sperre.
        $pdo->prepare('DELETE FROM ' . Db::table('login_attempts') . ' WHERE email = :email AND succeeded = 0')
            ->execute([':email' => $email]);
    }
}

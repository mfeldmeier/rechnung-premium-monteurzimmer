<?php

declare(strict_types=1);

/**
 * Nutzerverwaltung von der Kommandozeile. Es gibt keine Registrierung im
 * Browser - bei ein bis zwei Konten waere ein Self-Service nur zusaetzliche
 * Angriffsflaeche.
 *
 *   php bin/user.php add <email> <name>
 *   php bin/user.php passwd <email>
 *   php bin/user.php list
 */

use Pmz\Support\Db;
use Pmz\Support\Env;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';
Env::load($root . '/.env');

const MIN_PASSWORD_LENGTH = 12;

exit(main(array_slice($argv, 1)));

/** @param list<string> $args */
function main(array $args): int
{
    $command = $args[0] ?? '';

    try {
        return match ($command) {
            'add'    => cmdAdd($args[1] ?? '', $args[2] ?? ''),
            'passwd' => cmdPasswd($args[1] ?? ''),
            'list'   => cmdList(),
            default  => usage(),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL);

        return 1;
    }
}

function usage(): int
{
    fwrite(STDERR, <<<TXT
        Verwendung:
          php bin/user.php add <email> <name>
          php bin/user.php passwd <email>
          php bin/user.php list

        TXT);

    return 2;
}

function cmdAdd(string $email, string $name): int
{
    $email = normalizeEmail($email);
    $name  = trim($name);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, 'Bitte eine gueltige E-Mail angeben.' . PHP_EOL);

        return 2;
    }

    if (findUserId($email) !== null) {
        fwrite(STDERR, "Es gibt bereits einen Nutzer mit {$email}." . PHP_EOL);

        return 1;
    }

    $password = askPasswordTwice();

    $stmt = Db::pdo()->prepare(
        'INSERT INTO ' . Db::table('users') . ' (email, name, password_hash) VALUES (:email, :name, :hash)',
    );
    $stmt->execute([
        ':email' => $email,
        ':name'  => $name,
        ':hash'  => password_hash($password, PASSWORD_DEFAULT),
    ]);

    fwrite(STDOUT, "Nutzer {$email} angelegt." . PHP_EOL);

    return 0;
}

function cmdPasswd(string $email): int
{
    $email = normalizeEmail($email);
    $id    = findUserId($email);

    if ($id === null) {
        fwrite(STDERR, "Kein Nutzer mit {$email} gefunden." . PHP_EOL);

        return 1;
    }

    $password = askPasswordTwice();

    $stmt = Db::pdo()->prepare('UPDATE ' . Db::table('users') . ' SET password_hash = :hash WHERE id = :id');
    $stmt->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);

    // Nach einem Passwortwechsel die offenen Fehlversuche verwerfen, sonst
    // bleibt das Konto trotz neuem Passwort bis zum Fensterende gesperrt.
    Db::pdo()->prepare('DELETE FROM ' . Db::table('login_attempts') . ' WHERE email = :email AND succeeded = 0')
        ->execute([':email' => $email]);

    fwrite(STDOUT, "Passwort fuer {$email} geaendert." . PHP_EOL);

    return 0;
}

function cmdList(): int
{
    $rows = Db::pdo()
        ->query('SELECT id, email, name, is_active, last_login_at FROM ' . Db::table('users') . ' ORDER BY id')
        ->fetchAll();

    if ($rows === []) {
        fwrite(STDOUT, 'Keine Nutzer vorhanden.' . PHP_EOL);

        return 0;
    }

    foreach ($rows as $row) {
        fwrite(STDOUT, sprintf(
            "%3d  %-34s %-24s %-7s %s\n",
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['name'],
            ((int) $row['is_active']) === 1 ? 'aktiv' : 'gesperrt',
            $row['last_login_at'] ?? 'nie angemeldet',
        ));
    }

    return 0;
}

function normalizeEmail(string $email): string
{
    return strtolower(trim($email));
}

function findUserId(string $email): ?int
{
    $stmt = Db::pdo()->prepare('SELECT id FROM ' . Db::table('users') . ' WHERE email = :email');
    $stmt->execute([':email' => $email]);

    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

function askPasswordTwice(): string
{
    // Nicht-interaktiver Weg fuer eingeschraenkte Shells (Plesk, Cronjobs).
    // Der Wert kommt ueber die Umgebung und nicht als Argument, damit er nicht
    // in der Prozessliste auftaucht.
    $fromEnv = getenv('PMZ_PASSWORD');
    if (is_string($fromEnv) && $fromEnv !== '') {
        if (mb_strlen($fromEnv) < MIN_PASSWORD_LENGTH) {
            throw new RuntimeException('PMZ_PASSWORD ist kuerzer als ' . MIN_PASSWORD_LENGTH . ' Zeichen.');
        }

        return $fromEnv;
    }

    while (true) {
        $first = promptHidden('Passwort: ');

        if (mb_strlen($first) < MIN_PASSWORD_LENGTH) {
            fwrite(STDERR, 'Mindestens ' . MIN_PASSWORD_LENGTH . ' Zeichen, bitte erneut.' . PHP_EOL);
            continue;
        }

        if ($first !== promptHidden('Wiederholen: ')) {
            fwrite(STDERR, 'Die Eingaben stimmen nicht ueberein, bitte erneut.' . PHP_EOL);
            continue;
        }

        return $first;
    }
}

/**
 * Liest eine Zeile moeglichst ohne Bildschirmausgabe. Das Terminal wird in
 * jedem Fall zurueckgestellt - sonst bleibt die Shell nach einem Abbruch blind.
 *
 * Eingeschraenkte Shells wie die von Plesk haben weder stty noch zwingend
 * shell_exec. Dann wird die Eingabe sichtbar gelesen, aber vorher gewarnt:
 * lieber ein sichtbares Passwort als ein Abbruch mitten im Setup. Wer das
 * vermeiden will, setzt PMZ_PASSWORD.
 */
function promptHidden(string $label): string
{
    $hidden = stream_isatty(STDIN) && sttyAvailable();

    if (!$hidden) {
        fwrite(STDERR, 'Hinweis: Die Eingabe kann hier nicht verdeckt werden und bleibt sichtbar.' . PHP_EOL
            . 'Alternative: PMZ_PASSWORD=<passwort> php bin/user.php ...' . PHP_EOL);
    }

    fwrite(STDOUT, $label);

    if ($hidden) {
        shell_exec('stty -echo');
    }

    try {
        $line = fgets(STDIN);
    } finally {
        if ($hidden) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return rtrim($line === false ? '' : $line, "\r\n");
}

function sttyAvailable(): bool
{
    static $available = null;

    if ($available !== null) {
        return $available;
    }

    if (!function_exists('shell_exec') || in_array('shell_exec', explode(',', str_replace(' ', '', (string) ini_get('disable_functions'))), true)) {
        return $available = false;
    }

    $found = @shell_exec('command -v stty 2>/dev/null');

    return $available = is_string($found) && trim($found) !== '';
}

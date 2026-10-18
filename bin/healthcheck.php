<?php

declare(strict_types=1);

/**
 * Prueft nach dem Deploy, ob die Installation tatsaechlich arbeitsfaehig ist.
 *
 * Dateien zu zaehlen reicht dafuer nicht: der FTP-Sync hat 2.515 von 4.270
 * Dateien uebertragen und galt trotzdem als erfolgreich. Hier wird deshalb
 * nicht gezaehlt, sondern ausprobiert - Autoloader laden, Klassen aufloesen,
 * Datenbank verbinden, Schreibrecht testen.
 *
 *   php bin/healthcheck.php
 *
 * Rueckgabe 0 wenn alles traegt, sonst 1.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$root   = dirname(__DIR__);
$failed = 0;

function check(string $label, callable $probe): void
{
    global $failed;

    try {
        $detail = $probe();
        printf("  OK    %-28s %s\n", $label, (string) $detail);
    } catch (Throwable $e) {
        printf("  FEHLT %-28s %s\n", $label, $e->getMessage());
        $failed++;
    }
}

echo "Healthcheck {$root}\n";

check('PHP-Version', static function (): string {
    if (PHP_VERSION_ID < 80300) {
        throw new RuntimeException('braucht 8.3, laeuft auf ' . PHP_VERSION);
    }

    return PHP_VERSION;
});

check('vendor/autoload.php', static function () use ($root): string {
    $file = $root . '/vendor/autoload.php';
    if (!is_file($file)) {
        throw new RuntimeException('fehlt - Deploy war unvollstaendig');
    }
    require $file;

    return 'geladen';
});

check('mPDF', static function (): string {
    if (!class_exists(\Mpdf\Mpdf::class)) {
        throw new RuntimeException('Klasse nicht auffindbar');
    }

    return 'auffindbar';
});

check('ZUGFeRD', static function (): string {
    if (!class_exists(\horstoeko\zugferd\ZugferdDocumentBuilder::class)) {
        throw new RuntimeException('Klasse nicht auffindbar');
    }

    return 'auffindbar';
});

check('.env', static function () use ($root): string {
    if (!is_readable($root . '/.env')) {
        throw new RuntimeException('nicht lesbar');
    }
    \Pmz\Support\Env::load($root . '/.env');

    return 'gelesen';
});

check('Datenbank', static function (): string {
    return (string) \Pmz\Support\Db::pdo()->query('SELECT VERSION()')->fetchColumn();
});

check('Migrationen', static function (): string {
    $table = \Pmz\Support\Db::table('migrations');
    $rows  = \Pmz\Support\Db::pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

    if ((int) $rows === 0) {
        throw new RuntimeException('keine angewandt - php db/migrate.php ausfuehren');
    }

    return $rows . ' angewandt';
});

check('Wohnungen', static function (): string {
    $table = \Pmz\Support\Db::table('apartments');

    return \Pmz\Support\Db::pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn() . ' angelegt';
});

check('Benutzer', static function (): string {
    $table = \Pmz\Support\Db::table('users');
    $rows  = (int) \Pmz\Support\Db::pdo()->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();

    if ($rows === 0) {
        throw new RuntimeException('keiner angelegt - Anmeldung unmoeglich');
    }

    return $rows . ' vorhanden';
});

// Der Webserver laeuft unter einem anderen Benutzer als die SSH-Sitzung.
// Ein hier erfolgreicher Schreibtest beweist deshalb noch nicht, dass auch
// PHP-FPM schreiben darf - er faengt nur den haeufigeren Fall ab.
check('storage beschreibbar', static function () use ($root): string {
    $dir = $root . '/storage/invoices';
    if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
        throw new RuntimeException('konnte nicht angelegt werden');
    }

    $probe = $dir . '/.healthcheck';
    if (file_put_contents($probe, 'x') === false) {
        throw new RuntimeException('kein Schreibrecht');
    }
    unlink($probe);

    return $dir;
});

echo $failed === 0
    ? "\nAlles in Ordnung.\n"
    : "\n{$failed} Pruefung(en) fehlgeschlagen.\n";

exit($failed === 0 ? 0 : 1);

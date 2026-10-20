<?php

declare(strict_types=1);

/**
 * Loescht ALLE Rechnungen samt Positionen und setzt den Nummernkreis zurueck.
 *
 *   php bin/reset-invoices.php                  # zeigt nur, was passieren wuerde
 *   php bin/reset-invoices.php --force          # loescht wirklich
 *   php bin/reset-invoices.php --force --next=69 --year=2026
 *
 * Gedacht fuer das Ende der Testphase: die Entwuerfe aus dem Probebetrieb
 * sollen weg, und die erste echte Rechnung soll 69/2026 heissen.
 *
 * Danach ist nichts wiederherzustellen. Ohne --force wird deshalb nur gezaehlt.
 *
 * WARNUNG: Fuer bereits versandte Rechnungen ist das der falsche Weg. Sie
 * unterliegen der Aufbewahrungspflicht, und eine spaeter erneut vergebene
 * Nummer beschreibt dann zwei verschiedene Vorgaenge.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pmz\Support\Db;
use Pmz\Support\Env;

Env::load(__DIR__ . '/../.env');

$force = in_array('--force', $argv, true);
$next  = (int) (argValue($argv, '--next') ?? '69');
$year  = (int) (argValue($argv, '--year') ?? date('Y'));

if ($next < 1) {
    fwrite(STDERR, "--next muss mindestens 1 sein.\n");
    exit(1);
}

$pdo      = Db::pdo();
$invoices = Db::table('invoices');
$items    = Db::table('invoice_items');
$counters = Db::table('number_counters');

$gesamt      = (int) $pdo->query("SELECT COUNT(*) FROM {$invoices}")->fetchColumn();
$finalisiert = (int) $pdo->query("SELECT COUNT(*) FROM {$invoices} WHERE `status` <> 'draft'")->fetchColumn();
$positionen  = (int) $pdo->query("SELECT COUNT(*) FROM {$items}")->fetchColumn();

printf(
    "Bestand: %d Rechnung(en), davon %d finalisiert oder storniert, %d Position(en).\n",
    $gesamt,
    $finalisiert,
    $positionen,
);
printf("Danach:  Nummernkreis %d beginnt wieder bei %d.\n", $year, $next);

if (!$force) {
    echo "\nNichts gelöscht. Mit --force wird es ernst.\n";
    exit(0);
}

if ($finalisiert > 0) {
    // Finalisierte Rechnungen koennen beim Kunden sein. Das ist kein Grund
    // abzubrechen - aber einer, es deutlich zu sagen.
    printf(
        "\nACHTUNG: %d dieser Rechnungen sind finalisiert oder storniert.\n"
        . "Waren sie bereits beim Kunden, fehlen anschließend die Belege dazu.\n",
        $finalisiert,
    );
}

Db::transaction(function (PDO $pdo) use ($items, $invoices, $counters, $next, $year): void {
    // Erst die Positionen: sie zeigen per Fremdschluessel auf die Rechnungen.
    $pdo->exec("DELETE FROM {$items}");
    $pdo->exec("DELETE FROM {$invoices}");

    // last_seq ist die zuletzt VERGEBENE Nummer, nicht die naechste.
    $pdo->prepare(
        "INSERT INTO {$counters} (`number_year`, `last_seq`) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE `last_seq` = VALUES(`last_seq`)"
    )->execute([$year, $next - 1]);
});

// Erst nach dem Commit: ALTER TABLE loest in MySQL einen impliziten Commit
// aus und wuerde die Transaktion mittendrin beenden. Reine Kosmetik - damit
// die internen Ids wieder bei 1 anfangen.
$pdo->exec("ALTER TABLE {$invoices} AUTO_INCREMENT = 1");

printf("\nFertig. %d Rechnung(en) und %d Position(en) gelöscht.\n", $gesamt, $positionen);
printf("Die nächste Rechnung bekommt die Nummer %d/%d.\n", $next, $year);

function argValue(array $argv, string $name): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $name . '=')) {
            return substr($arg, strlen($name) + 1);
        }
    }

    return null;
}

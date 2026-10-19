<?php

declare(strict_types=1);

/**
 * Holt neue secra-Buchungsbestaetigungen aus dem Postfach und legt daraus
 * Rechnungsentwuerfe an. Gedacht fuer den Cron-Lauf, etwa alle zehn Minuten:
 *
 *   php bin/import-bookings.php
 *
 * Verarbeitete Mails wandern in IMAP_DONE_FOLDER, nicht verwertbare in
 * IMAP_FAIL_FOLDER. Beides, damit der Posteingang nicht zumuellt und ein
 * Fehlschlag sichtbar bleibt, statt im Log zu versanden.
 *
 * Mit --dry-run wird nichts gespeichert und nichts verschoben.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pmz\Domain\Booking;
use Pmz\Import\BookingPdfReader;
use Pmz\Service\BookingImporter;
use Pmz\Support\Env;
use Webklex\PHPIMAP\ClientManager;

Env::load(__DIR__ . '/../.env');

$dryRun = in_array('--dry-run', $argv, true);
$reader = new BookingPdfReader();
$import = new BookingImporter();

$host = Env::get('IMAP_HOST', '');

if ($host === '') {
    fwrite(STDERR, "IMAP_HOST ist nicht gesetzt - ohne Postfach kein Import.\n");
    exit(1);
}

$inbox = Env::get('IMAP_FOLDER', 'INBOX');
$done  = Env::get('IMAP_DONE_FOLDER', 'INBOX.Erledigt');
$fail  = Env::get('IMAP_FAIL_FOLDER', 'INBOX.Fehler');

$client = (new ClientManager())->make([
    'host'          => $host,
    'port'          => (int) Env::get('IMAP_PORT', '993'),
    'encryption'    => Env::get('IMAP_ENCRYPTION', 'ssl'),
    // Auf demselben Server laeuft das ueber 127.0.0.1; dort traegt das
    // Zertifikat den oeffentlichen Hostnamen und passt nie zur IP.
    'validate_cert' => Env::get('IMAP_VALIDATE_CERT', '1') === '1',
    'username'      => Env::get('IMAP_USER', ''),
    'password'      => Env::get('IMAP_PASS', ''),
    'protocol'      => 'imap',
]);

try {
    $client->connect();
} catch (Throwable $e) {
    fwrite(STDERR, 'Postfach nicht erreichbar: ' . $e->getMessage() . "\n");
    exit(1);
}

$folder = $client->getFolderByPath($inbox);

if ($folder === null) {
    fwrite(STDERR, "Ordner {$inbox} gibt es nicht.\n");
    exit(1);
}

$messages = $folder->query()->unseen()->leaveUnread()->get();

$gelesen       = 0;
$angelegt      = 0;
$uebersprungen = 0;
$fehler        = 0;

foreach ($messages as $message) {
    $betreff  = (string) $message->getSubject();
    $anhaenge = $message->getAttachments()->filter(
        static fn ($a): bool => str_ends_with(strtolower((string) $a->getName()), '.pdf'),
    );

    if ($anhaenge->count() === 0) {
        // Keine Bestaetigung, sondern irgendeine andere Mail. Unberuehrt lassen.
        continue;
    }

    $gelesen++;
    $buchungen = [];
    $probleme  = [];

    foreach ($anhaenge as $anhang) {
        try {
            $buchungen[] = $reader->fromBytes((string) $anhang->getContent());
        } catch (Throwable $e) {
            $probleme[] = $anhang->getName() . ': ' . $e->getMessage();
        }
    }

    if ($buchungen === []) {
        $fehler++;
        meldung('FEHLER', $betreff, $probleme === [] ? ['kein lesbarer Anhang'] : $probleme);

        if (!$dryRun) {
            $message->move($fail);
        }

        continue;
    }

    foreach ($buchungen as $buchung) {
        if ($dryRun) {
            meldung('PROBELAUF', $betreff, [zusammenfassung($buchung)]);

            continue;
        }

        try {
            $ergebnis = $import->import($buchung);
        } catch (Throwable $e) {
            $fehler++;
            meldung('FEHLER', $betreff, [$buchung->bookingRef . ': ' . $e->getMessage()]);
            $message->move($fail);

            continue 2;
        }

        if ($ergebnis['created']) {
            $angelegt++;
            meldung('ANGELEGT', $betreff, array_merge(
                [sprintf('Entwurf #%d - %s', $ergebnis['invoiceId'], zusammenfassung($buchung))],
                $ergebnis['warnings'],
            ));
        } else {
            $uebersprungen++;
            meldung('BEKANNT', $betreff, [sprintf(
                'Buchung %s liegt schon als Entwurf #%d vor.',
                $buchung->bookingRef,
                $ergebnis['invoiceId'],
            )]);
        }
    }

    if (!$dryRun) {
        $message->move($done);
    }
}

$client->disconnect();

printf(
    "%s: %d Mail(en) mit PDF, %d Entwürfe angelegt, %d bereits bekannt, %d fehlgeschlagen.\n",
    $dryRun ? 'Probelauf' : 'Import',
    $gelesen,
    $angelegt,
    $uebersprungen,
    $fehler,
);

exit($fehler > 0 ? 1 : 0);

function zusammenfassung(Booking $b): string
{
    return sprintf(
        'Buchung %s, %s bis %s, %d Gäste, %s EUR inkl. Kaution',
        $b->bookingRef,
        $b->arrival,
        $b->departure,
        $b->guests,
        number_format(($b->totalCents + $b->depositCents) / 100, 2, ',', '.'),
    );
}

/** @param list<string> $zeilen */
function meldung(string $art, string $betreff, array $zeilen): void
{
    printf("[%s] %s\n", $art, $betreff);

    foreach ($zeilen as $zeile) {
        printf("    %s\n", $zeile);
    }

    if ($art === 'FEHLER') {
        error_log(sprintf('[pmz-import] %s | %s', $betreff, implode(' | ', $zeilen)));
    }
}

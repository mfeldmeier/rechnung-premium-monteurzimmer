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
use Pmz\Import\MailFilter;
use Pmz\Service\BookingImporter;
use Pmz\Support\Env;
use Webklex\PHPIMAP\ClientManager;

Env::load(__DIR__ . '/../.env');

$dryRun = in_array('--dry-run', $argv, true);
$reader = new BookingPdfReader();
$import = new BookingImporter();

// Das Postfach liegt auf derselben Maschine - dafuer braucht es kein Secret.
$host = Env::get('IMAP_HOST', '127.0.0.1');
$user = Env::get('IMAP_USER', '');
$pass = Env::get('IMAP_PASS', '');

// Gefragt ist nach den Zugangsdaten, nicht nach dem Host: der hat eine
// brauchbare Vorgabe, Benutzer und Passwort nicht.
if ($user === '' || $pass === '') {
    fwrite(STDERR, sprintf(
        "Zugangsdaten zum Postfach fehlen (%s).\n"
        . "Sie entstehen beim Deploy aus den GitHub-Secrets IMAP_USER und IMAP_PASS.\n"
        . "Steht in der .env auf dem Server keine IMAP-Zeile, lief der Deploy noch mit der alten Vorlage.\n",
        implode(' und ', array_filter([
            $user === '' ? 'IMAP_USER' : '',
            $pass === '' ? 'IMAP_PASS' : '',
        ])),
    ));
    exit(1);
}

$inbox = Env::get('IMAP_FOLDER', 'INBOX');
$done  = Env::get('IMAP_DONE_FOLDER', 'INBOX.Erledigt');
$fail  = Env::get('IMAP_FAIL_FOLDER', 'INBOX.Fehler');

// Nur die Benachrichtigungen des Channel Managers. Alles andere im Posteingang
// bleibt unberuehrt - auch dann, wenn ein PDF daran haengt.
$sender = strtolower(Env::get('IMAP_SENDER', 'channel-manager@secra.de'));
$filter = new MailFilter($sender, Env::get('IMAP_SUBJECT_PREFIX', 'Buchungsbestätigung Nr'));

$client = (new ClientManager())->make([
    'host'          => $host,
    'port'          => (int) Env::get('IMAP_PORT', '993'),
    'encryption'    => Env::get('IMAP_ENCRYPTION', 'ssl'),
    // Auf demselben Server laeuft das ueber 127.0.0.1; dort traegt das
    // Zertifikat den oeffentlichen Hostnamen und passt nie zur IP.
    'validate_cert' => Env::get('IMAP_VALIDATE_CERT', '1') === '1',
    'username'      => $user,
    'password'      => $pass,
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

// Nach Absender wird serverseitig gesucht, nach Betreff nicht: "Buchungs-
// bestätigung" enthaelt ein Umlaut-Zeichen, und IMAP SEARCH mit Nicht-ASCII
// ist je nach Server eine Wundertuete. Der Betreff wird deshalb hier geprueft.
$messages = $folder->query()->unseen()->from($sender)->leaveUnread()->get();

$gelesen       = 0;
$angelegt      = 0;
$uebersprungen = 0;
$fehler        = 0;

foreach ($messages as $message) {
    $betreff = (string) $message->getSubject();

    $absender = array_map(
        static fn ($von): string => (string) ($von->mail ?? ''),
        $message->getFrom() ?? [],
    );

    if (!$filter->matchesSubject($betreff) || !$filter->isFromSender($absender)) {
        continue;
    }

    $anhaenge = $message->getAttachments()->filter(
        static fn ($a): bool => str_ends_with(strtolower((string) $a->getName()), '.pdf'),
    );

    if ($anhaenge->count() === 0) {
        // Betreff passt, aber ohne PDF ist nichts zu holen. Sichtbar machen,
        // statt stillschweigend zu uebergehen - das waere ein Formatwechsel.
        $fehler++;
        meldung('FEHLER', $betreff, ['Buchungsbestätigung ohne PDF-Anhang.']);

        if (!$dryRun) {
            $message->move($fail);
        }

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

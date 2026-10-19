<?php

declare(strict_types=1);

/**
 * Liest eine Buchungsbestaetigung von der Platte - fuer Nachtraege und zum
 * Pruefen, ob der Parser ein neues Dokument versteht:
 *
 *   php bin/import-booking-file.php ~/Downloads/Buchung.pdf
 *   php bin/import-booking-file.php ~/Downloads/Buchung.pdf --dry-run
 *
 * Mit --dry-run wird nur gezeigt, was erkannt wurde. Das braucht keine
 * Datenbank und ist der schnellste Weg, eine Formataenderung zu erkennen.
 */

require __DIR__ . '/../vendor/autoload.php';

use Pmz\Import\BookingPdfReader;
use Pmz\Service\BookingImporter;
use Pmz\Support\Env;

Env::load(__DIR__ . '/../.env');

$args   = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--dry-run'));
$dryRun = in_array('--dry-run', $argv, true);
$path   = $args[0] ?? '';

if ($path === '') {
    fwrite(STDERR, "Aufruf: php bin/import-booking-file.php <datei.pdf> [--dry-run]\n");
    exit(1);
}

try {
    $booking = (new BookingPdfReader())->fromFile($path);
} catch (Throwable $e) {
    fwrite(STDERR, 'Nicht lesbar: ' . $e->getMessage() . "\n");
    exit(1);
}

printf(
    "Buchung:     %s%s\n",
    $booking->bookingRef,
    $booking->externalRef !== null ? " (extern {$booking->externalRef})" : '',
);
printf("Objekt:      %s / %s\n", $booking->objectNo ?? '-', $booking->objectName ?? '-');
printf(
    "Zeitraum:    %s bis %s (%d Nächte, %d Gäste, Menge %.0f)\n",
    $booking->arrival,
    $booking->departure,
    $booking->nights,
    $booking->guests,
    $booking->quantity(),
);
printf("Empfänger:   %s\n", str_replace("\n", "\n             ", $booking->recipientText()));
printf("Miete:       %s\n", euro($booking->rentCents));
printf("Reinigung:   %s\n", euro($booking->cleaningCents));
printf("Kaution:     %s\n", euro($booking->depositCents));
printf("Reisepreis:  %s\n", euro($booking->totalCents));

if ($dryRun) {
    echo "\nProbelauf - nichts gespeichert.\n";
    exit(0);
}

try {
    $ergebnis = (new BookingImporter())->import($booking);
} catch (Throwable $e) {
    fwrite(STDERR, "\nImport fehlgeschlagen: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n" . ($ergebnis['created']
    ? "Entwurf #{$ergebnis['invoiceId']} angelegt.\n"
    : "Buchung war schon bekannt, Entwurf #{$ergebnis['invoiceId']} bleibt unverändert.\n");

foreach ($ergebnis['warnings'] as $hinweis) {
    echo "Hinweis: {$hinweis}\n";
}

function euro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' EUR';
}

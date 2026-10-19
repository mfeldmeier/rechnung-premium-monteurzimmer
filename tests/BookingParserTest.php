<?php

declare(strict_types=1);

namespace Pmz\Tests;

use DomainException;
use Pmz\Import\BookingParser;
use PHPUnit\Framework\TestCase;

final class BookingParserTest extends TestCase
{
    /**
     * Die Vorlage ist eine echte secra-Bestaetigung mit ausgetauschten
     * Personendaten. Betraege und Aufbau sind unveraendert - daran haengt der
     * Wert des Tests.
     */
    private function vorlage(): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/secra-buchungsbestaetigung.txt');
    }

    public function testLiestDieBuchung(): void
    {
        $booking = (new BookingParser())->parse($this->vorlage());

        self::assertSame('3100001', $booking->bookingRef);
        self::assertSame('6700000001', $booking->externalRef);
        self::assertSame('310939', $booking->objectNo);
        self::assertSame('2026-11-02', $booking->arrival);
        self::assertSame('2026-11-10', $booking->departure);
        self::assertSame(8, $booking->nights);
        self::assertSame(2, $booking->guests);
    }

    /** Der Werbezusatz hinter dem Doppelpunkt gehoert nicht in den Wohnungsnamen. */
    public function testUnterkunftStehtEineZeileTieferAlsIhrLabel(): void
    {
        $booking = (new BookingParser())->parse($this->vorlage());

        self::assertSame('Ferienwohnung Orange 1.OG Kassel', $booking->objectName);
    }

    /** Betten mal Naechte, genau wie im Rechenkern. */
    public function testMengeAusGaestenUndNaechten(): void
    {
        self::assertSame(16.0, (new BookingParser())->parse($this->vorlage())->quantity());
    }

    /** "EUR 60,--" meint 60,00 - die Striche sind keine Nachkommastellen. */
    public function testBetraege(): void
    {
        $booking = (new BookingParser())->parse($this->vorlage());

        self::assertSame(53783, $booking->rentCents);
        self::assertSame(6000, $booking->cleaningCents);
        self::assertSame(10000, $booking->depositCents);
        self::assertSame(59783, $booking->totalCents);
    }

    /**
     * Telefon und E-Mail gehoeren nicht in den Adressblock. Das Land schon -
     * ohne die Zeile stuende im ZUGFeRD-XML faelschlich DE.
     */
    public function testEmpfaengerblockOhneKontaktdatenMitLand(): void
    {
        $booking = (new BookingParser())->parse($this->vorlage());

        self::assertSame(
            ['Frau Ana Marić', 'Ulica kralja Tomislava 2E', '10360 Sesvete', 'Kroatien'],
            $booking->recipientLines,
        );
        self::assertSame('+385 91 234 5678', $booking->guestPhone);
        self::assertSame('gast.123456@guest.booking.example', $booking->guestEmail);
    }

    /** Inlandsbuchungen brauchen keine Landeszeile. */
    public function testDeutscheVorwahlErzeugtKeineLandeszeile(): void
    {
        $text    = str_replace('-+385 91 234 5678', '-+49 561 1234567', $this->vorlage());
        $booking = (new BookingParser())->parse($text);

        self::assertSame(['Frau Ana Marić', 'Ulica kralja Tomislava 2E', '10360 Sesvete'], $booking->recipientLines);
    }

    /**
     * Die wichtigste Pruefung: Stimmen die Positionen nicht mit dem
     * ausgewiesenen Reisepreis ueberein, darf daraus keine Rechnung entstehen.
     */
    public function testAbweichendeSummeWirdAbgelehnt(): void
    {
        $text = str_replace("Reisepreis\tEUR 597,83", "Reisepreis\tEUR 599,00", $this->vorlage());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/ergeben 597,83.*ausgewiesen sind 599,00/s');

        (new BookingParser())->parse($text);
    }

    /** Dasselbe fuer widersprechende Naechte. */
    public function testWidersprechendeNaechteWerdenAbgelehnt(): void
    {
        $text = str_replace("Übernachtungen:\t8 Nächte", "Übernachtungen:\t9 Nächte", $this->vorlage());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/9 Nächte angegeben.*ergeben sich 8/s');

        (new BookingParser())->parse($text);
    }

    public function testFremdesDokumentWirdAbgelehnt(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/keine secra-Buchungsbestätigung/');

        (new BookingParser())->parse("Sehr geehrte Damen und Herren,\n\nanbei unsere Rechnung.\n");
    }
}

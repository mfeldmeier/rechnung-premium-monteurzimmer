<?php

declare(strict_types=1);

namespace Pmz\Tests;

use Pmz\Domain\RecipientBlock;
use PHPUnit\Framework\TestCase;

final class RecipientBlockTest extends TestCase
{
    /** Der Normalfall: Firma, Ansprechpartner, Strasse, Anschrift. */
    public function testVierZeiligerBlock(): void
    {
        $block = RecipientBlock::parse(
            "Nordwestdeutsche Anlagenbau GmbH\n"
            . "z. Hd. Herrn Oberhausermann\n"
            . "Industriepark Nord 7\n"
            . '49084 Osnabrück'
        );

        self::assertTrue($block->isComplete());
        self::assertSame('Nordwestdeutsche Anlagenbau GmbH', $block->snapshot['company']);
        self::assertSame('z. Hd. Herrn Oberhausermann', $block->snapshot['contact_name']);
        self::assertSame('Industriepark Nord 7', $block->snapshot['street']);
        self::assertSame('49084', $block->snapshot['postcode']);
        self::assertSame('Osnabrück', $block->snapshot['city']);
        self::assertSame('DE', $block->snapshot['country_code']);
        self::assertCount(4, $block->lines);
    }

    /** Ohne Strasse: zwei Zeilen reichen, die erste ist dann der Name. */
    public function testNameUndAnschriftReichen(): void
    {
        $block = RecipientBlock::parse("Musterbau GmbH\n34117 Kassel");

        self::assertTrue($block->isComplete());
        self::assertSame('Musterbau GmbH', $block->snapshot['company']);
        self::assertSame('', $block->snapshot['street']);
        self::assertSame('Kassel', $block->snapshot['city']);
    }

    /**
     * Die Hausnummer steht hinten, die Postleitzahl vorn - sonst waere
     * "Am Bahnhof 1234" die Anschrift und Kassel die Strasse.
     */
    public function testHausnummerWirdNichtFuerEinePostleitzahlGehalten(): void
    {
        $block = RecipientBlock::parse("Musterbau GmbH\nAm Bahnhof 1234\n34117 Kassel");

        self::assertSame('Am Bahnhof 1234', $block->snapshot['street']);
        self::assertSame('34117', $block->snapshot['postcode']);
    }

    /** Das Land darf als eigene Zeile oder als Praefix der Anschrift kommen. */
    public function testLandAusEigenerZeile(): void
    {
        $block = RecipientBlock::parse("Alpen Bau AG\nSeestrasse 4\n8001 Zürich\nSchweiz");

        self::assertTrue($block->isComplete());
        self::assertSame('CH', $block->snapshot['country_code']);
        self::assertSame('Zürich', $block->snapshot['city']);
        // Die Landeszeile bleibt im Block stehen, sie gehoert aufs Papier.
        self::assertCount(4, $block->lines);
    }

    public function testLandAusPraefix(): void
    {
        $block = RecipientBlock::parse("Alpen Bau GmbH\nSalzachweg 2\nAT-5020 Salzburg");

        self::assertSame('AT', $block->snapshot['country_code']);
        self::assertSame('5020', $block->snapshot['postcode']);
        self::assertSame('Salzburg', $block->snapshot['city']);
    }

    /** Leerzeilen und doppelte Leerzeichen reissen sonst den Adressblock auf. */
    public function testLeerzeilenFliegenRaus(): void
    {
        $block = RecipientBlock::parse("  Musterbau GmbH  \n\n\nAm Bahnhof  1\n\n34117 Kassel\n\n");

        self::assertSame(['Musterbau GmbH', 'Am Bahnhof 1', '34117 Kassel'], $block->lines);
    }

    /**
     * Fehlt die Anschrift, bleibt der Block benutzbar - nur finalisieren laesst
     * sich damit nicht, weil ZUGFeRD Ort und Land verlangt.
     */
    public function testOhneAnschriftBleibtDerNameStehen(): void
    {
        $block = RecipientBlock::parse("Musterbau GmbH\nIrgendwas");

        self::assertFalse($block->isComplete());
        self::assertStringContainsString('Postleitzahl', (string) $block->problem);
        self::assertSame('Musterbau GmbH', $block->snapshot['company']);
    }

    public function testLeererBlock(): void
    {
        $block = RecipientBlock::parse("   \n  \n");

        self::assertFalse($block->isComplete());
        self::assertSame([], $block->lines);
        self::assertSame('', $block->snapshot['company']);
    }

    /** Steht nur eine Anschrift da, fehlt der Name - das muss auffallen. */
    public function testAnschriftOhneNamen(): void
    {
        $block = RecipientBlock::parse('34117 Kassel');

        self::assertFalse($block->isComplete());
        self::assertStringContainsString('Name', (string) $block->problem);
    }
}

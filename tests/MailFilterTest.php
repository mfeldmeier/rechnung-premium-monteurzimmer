<?php

declare(strict_types=1);

namespace Pmz\Tests;

use Pmz\Import\MailFilter;
use PHPUnit\Framework\TestCase;

final class MailFilterTest extends TestCase
{
    private function filter(): MailFilter
    {
        return new MailFilter();
    }

    public function testNimmtDieBuchungsbestaetigung(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->isFromSender(['channel-manager@secra.de']));
        self::assertTrue($filter->matchesSubject('Buchungsbestätigung Nr. 3238295'));
    }

    /** Gross- und Kleinschreibung sind in Adressen und Betreffs nicht verlaesslich. */
    public function testSchreibweiseIstEgal(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->isFromSender(['Channel-Manager@Secra.DE']));
        self::assertTrue($filter->matchesSubject('BUCHUNGSBESTÄTIGUNG NR. 3238295'));
    }

    /**
     * IMAP SEARCH FROM vergleicht als Teilzeichenkette. Eine Adresse, die den
     * erwarteten Absender nur enthaelt, darf nicht durchrutschen.
     */
    public function testAehnlicheAbsenderWerdenAbgelehnt(): void
    {
        $filter = $this->filter();

        self::assertFalse($filter->isFromSender(['channel-manager@nicht-secra.de']));
        self::assertFalse($filter->isFromSender(['xchannel-manager@secra.de']));
        self::assertFalse($filter->isFromSender([]));
    }

    public function testAndereMailsBleibenLiegen(): void
    {
        $filter = $this->filter();

        self::assertFalse($filter->matchesSubject('Ihre Rechnung Nr. 4711'));
        self::assertFalse($filter->matchesSubject('Stornierung Nr. 3238295'));
        self::assertFalse($filter->matchesSubject(''));
    }

    /** Eine weitergeleitete Bestaetigung soll nicht liegen bleiben. */
    public function testWeiterleitungsKuerzelStoerenNicht(): void
    {
        $filter = $this->filter();

        self::assertTrue($filter->matchesSubject('WG: Buchungsbestätigung Nr. 3238295'));
        self::assertTrue($filter->matchesSubject('Fwd: AW: Buchungsbestätigung Nr. 3238295'));
    }

    /** Falls secra den Betreff eines Tages ohne Umlaut schreibt. */
    public function testUmlautloseSchreibweiseGreiftAuch(): void
    {
        self::assertTrue($this->filter()->matchesSubject('Buchungsbestaetigung Nr. 3238295'));
    }

    /** Beides ist ueber die .env einstellbar, falls secra umzieht. */
    public function testAbsenderUndBetreffSindEinstellbar(): void
    {
        $filter = new MailFilter('buchungen@example.org', 'Neue Buchung');

        self::assertTrue($filter->isFromSender(['buchungen@example.org']));
        self::assertTrue($filter->matchesSubject('Neue Buchung 12345'));
        self::assertFalse($filter->isFromSender(['channel-manager@secra.de']));
    }
}

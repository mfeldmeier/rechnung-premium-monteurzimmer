<?php

declare(strict_types=1);

namespace Pmz\Tests;

use InvalidArgumentException;
use Pmz\Domain\Calculator;
use Pmz\Domain\LineItem;
use Pmz\Domain\TaxCategory;
use Pmz\Support\Format;
use PHPUnit\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    /**
     * Der Abnahmefall: Rechnung 68/2026 aus "Vorlage Rechnung.pdf".
     * Wohnung Kiwi, 9.10.-31.10.2026, 2 Betten / 22 Naechte zu 28 EUR brutto,
     * dazu Endreinigung 60 EUR und Kaution 100 EUR.
     */
    public function testVorlageRechnung68(): void
    {
        $totals = Calculator::compute($this->vorlagePositionen());

        self::assertSame(123200, $totals->lineGrossCents[0], 'Uebernachtung brutto');
        self::assertSame(6000, $totals->lineGrossCents[1], 'Endreinigung brutto');
        self::assertSame(10000, $totals->lineGrossCents[2], 'Kaution brutto');

        [$sieben, $neunzehn, $befreit] = $totals->groups;

        self::assertSame(700, $sieben->rateBp);
        self::assertSame(115140, $sieben->netCents);
        self::assertSame(8060, $sieben->taxCents);

        self::assertSame(1900, $neunzehn->rateBp);
        self::assertSame(5042, $neunzehn->netCents);
        self::assertSame(958, $neunzehn->taxCents);

        self::assertSame(TaxCategory::EXEMPT, $befreit->category);
        self::assertSame(10000, $befreit->netCents);
        self::assertSame(0, $befreit->taxCents);

        self::assertSame(130182, $totals->netTotalCents);
        self::assertSame(9018, $totals->taxTotalCents);
        self::assertSame(139200, $totals->grossTotalCents);
        self::assertSame('1.392,00 €', Format::euro($totals->grossTotalCents));
    }

    public function testNaechteAusDatumsspanne(): void
    {
        self::assertSame(22, LineItem::nightsBetween('2026-10-09', '2026-10-31'));
        self::assertSame(1, LineItem::nightsBetween('2026-12-31', '2027-01-01'));
        self::assertSame(0, LineItem::nightsBetween('2026-10-09', '2026-10-09'));
    }

    public function testAbreiseVorAnreiseWirdAbgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LineItem::nightsBetween('2026-10-31', '2026-10-09');
    }

    public function testBefreiteZeileOhneBegruendungWirdAbgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LineItem(LineItem::KIND_DEPOSIT, 'Kaution', 1.0, 10000, 0, TaxCategory::EXEMPT);
    }

    /**
     * EN16931 BR-CO-17: Gruppensteuer muss dem Produkt aus Gruppennetto und
     * Satz entsprechen. Die Schematron prueft das mit einer Toleranz von
     * 0,02 EUR - exakte Gleichheit ist beim Rechnen aus Bruttopreisen gar nicht
     * erreichbar, weil nicht jeder Bruttobetrag bei 19 % darstellbar ist
     * (3 Cent brutto etwa gibt es nicht). Gemessenes Maximum ueber 200.000
     * Betraege je Satz: 0,0059 EUR.
     */
    public function testSteuerProGruppeBleibtInnerhalbDerToleranz(): void
    {
        $toleranzCent = 2.0;

        foreach ([500, 700, 1000, 1900] as $rateBp) {
            for ($gross = 1; $gross <= 50000; $gross += 3) {
                $net = Calculator::netFromGross($gross, $rateBp);
                $tax = $gross - $net;

                self::assertLessThanOrEqual(
                    $toleranzCent,
                    abs($tax - $net * $rateBp / 10000),
                    "Ausserhalb der Toleranz bei brutto {$gross} und Satz {$rateBp}"
                );
            }
        }
    }

    /** Die Bruttosumme muss exakt dem entsprechen, was eingegeben wurde. */
    public function testBruttosummeBleibtErhalten(): void
    {
        $items = [];
        foreach ([1, 7, 33, 99, 1234, 5555] as $i => $cents) {
            $items[] = new LineItem(
                LineItem::KIND_CUSTOM,
                "Position {$i}",
                1.0,
                $cents,
                $i % 2 === 0 ? 700 : 1900,
            );
        }

        $totals = Calculator::compute($items);

        self::assertSame(array_sum($totals->lineGrossCents), $totals->grossTotalCents);
        self::assertSame($totals->netTotalCents + $totals->taxTotalCents, $totals->grossTotalCents);
        self::assertSame(array_sum($totals->lineNetCents), $totals->netTotalCents);
    }

    public function testVerteilungSummiertExakt(): void
    {
        foreach ([[100, [1, 1, 1]], [9999, [3, 5, 7, 11]], [1, [1, 1]], [0, [5, 5]]] as [$total, $weights]) {
            $shares = Calculator::allocate($total, $weights);

            self::assertSame($total, array_sum($shares));
            self::assertCount(count($weights), $shares);
        }
    }

    public function testVerteilungOhneGewichte(): void
    {
        self::assertSame([50, 0, 0], Calculator::allocate(50, [0, 0, 0]));
        self::assertSame([], Calculator::allocate(0, []));
    }

    public function testDarstellungWieInDerVorlage(): void
    {
        self::assertSame('9.10.-31.10.2026', Format::dateRange('2026-10-09', '2026-10-31'));
        self::assertSame('27.9.2026', Format::date('2026-09-27'));
        self::assertSame('7', Format::percent(700));
        self::assertSame('19', Format::percent(1900));
        self::assertSame('7,5', Format::percent(750));
        self::assertSame('1.151,40', Format::money(115140));
    }

    /** @return LineItem[] */
    private function vorlagePositionen(): array
    {
        return [
            LineItem::accommodation('Kiwi', 2, '2026-10-09', '2026-10-31', 2800),
            new LineItem(LineItem::KIND_CLEANING, 'Endreinigung', 1.0, 6000, 1900),
            new LineItem(
                LineItem::KIND_DEPOSIT,
                'Kaution',
                1.0,
                10000,
                0,
                TaxCategory::EXEMPT,
                'Kaution – durchlaufender Posten, kein Entgelt i.S.d. § 10 UStG',
            ),
        ];
    }
}

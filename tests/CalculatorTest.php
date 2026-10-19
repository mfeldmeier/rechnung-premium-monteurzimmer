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

    /**
     * Secra gibt einen Endpreis inklusive Gebuehren vor. Der muss auf den Cent
     * stehen bleiben, auch wenn er sich nicht glatt auf einen Nachtpreis
     * zurueckrechnen laesst.
     */
    public function testEndpreisBleibtExaktErhalten(): void
    {
        $item = new LineItem(
            kind: LineItem::KIND_ACCOMMODATION,
            description: 'Wohnung Kiwi',
            quantity: 44.0,
            unitPriceGrossCents: 0,
            taxRateBp: 700,
            beds: 2,
            nights: 22,
            priceMode: LineItem::MODE_TOTAL,
            lineTotalGrossCents: 125000,
        );

        self::assertSame(125000, $item->grossCents());
        self::assertEqualsWithDelta(2840.909, $item->unitPriceCents(), 0.001);
        self::assertSame('28,4091', Format::unitPrice($item->unitPriceCents()));

        // Der angezeigte Nachtpreis muss sich zur Zeilensumme zurueckrechnen,
        // sonst findet ein nachrechnender Kunde eine Differenz.
        self::assertSame(125000, (int) round($item->unitPriceCents() * 44));

        $totals = Calculator::compute([$item]);
        self::assertSame(125000, $totals->grossTotalCents);
        self::assertSame(116822, $totals->netTotalCents);
        self::assertSame(8178, $totals->taxTotalCents);
    }

    public function testTagespreisUndEndpreisSindUmkehrbar(): void
    {
        $perNight = LineItem::accommodation('Kiwi', 2, '2026-10-09', '2026-10-31', 2800);
        self::assertSame(123200, $perNight->grossCents());

        $asTotal = new LineItem(
            kind: LineItem::KIND_ACCOMMODATION,
            description: 'Wohnung Kiwi',
            quantity: 44.0,
            unitPriceGrossCents: 0,
            taxRateBp: 700,
            priceMode: LineItem::MODE_TOTAL,
            lineTotalGrossCents: 123200,
        );

        // Gleicher Betrag, egal von welcher Seite eingegeben.
        self::assertSame($perNight->grossCents(), $asTotal->grossCents());
        self::assertSame(
            Format::unitPrice($perNight->unitPriceCents()),
            Format::unitPrice($asTotal->unitPriceCents()),
        );
    }

    public function testEndpreisModusOhneSummeWirdAbgelehnt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LineItem(
            kind: LineItem::KIND_CUSTOM,
            description: 'Ohne Summe',
            quantity: 1.0,
            unitPriceGrossCents: 0,
            taxRateBp: 1900,
            priceMode: LineItem::MODE_TOTAL,
        );
    }

    public function testEinzelpreisDarstellung(): void
    {
        self::assertSame('28,00', Format::unitPrice(2800.0));
        self::assertSame('28,4091', Format::unitPrice(2840.909090909));
        self::assertSame('28,50', Format::unitPrice(2850.0));
        self::assertSame('1,00', Format::unitPrice(100.0));
        self::assertSame('0,00', Format::unitPrice(0.0));
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
    /**
     * Netto-Eingabe: aus 100,00 EUR bei 19 % werden exakt 119,00 EUR brutto,
     * und der Weg zurueck landet wieder auf 100,00 EUR netto.
     */
    public function testNettoEingabeWirdHochgerechnet(): void
    {
        $item = new LineItem(
            kind: LineItem::KIND_CLEANING,
            description: 'Endreinigung',
            quantity: 1.0,
            unitPriceGrossCents: 10000,
            taxRateBp: 1900,
            priceIncludesTax: false,
        );

        self::assertSame(11900, $item->grossCents());
        self::assertSame(11900.0, $item->unitPriceCents());

        $totals = Calculator::compute([$item]);

        self::assertSame(10000, $totals->netTotalCents);
        self::assertSame(1900, $totals->taxTotalCents);
        self::assertSame(11900, $totals->grossTotalCents);
    }

    /**
     * Dieselbe Rechnung einmal netto erfasst: die Zeilensummen sind die
     * Nettobetraege, die Steuer kommt obendrauf. Der Endpreis-Modus muss dabei
     * genauso hochgerechnet werden wie der Nachtpreis.
     */
    public function testNettoRechnungMitGemischtenSaetzen(): void
    {
        $totals = Calculator::compute([
            new LineItem(
                kind: LineItem::KIND_ACCOMMODATION,
                description: 'Wohnung Kiwi',
                quantity: 44.0,
                unitPriceGrossCents: 2800,
                taxRateBp: 700,
                beds: 2,
                nights: 22,
                priceIncludesTax: false,
            ),
            new LineItem(
                kind: LineItem::KIND_ACCOMMODATION,
                description: 'Wohnung Ananas',
                quantity: 60.0,
                unitPriceGrossCents: 0,
                taxRateBp: 700,
                beds: 4,
                nights: 15,
                priceMode: LineItem::MODE_TOTAL,
                lineTotalGrossCents: 198750,
                priceIncludesTax: false,
            ),
            new LineItem(
                kind: LineItem::KIND_CLEANING,
                description: 'Endreinigung',
                quantity: 2.0,
                unitPriceGrossCents: 6000,
                taxRateBp: 1900,
                priceIncludesTax: false,
            ),
            new LineItem(
                kind: LineItem::KIND_DEPOSIT,
                description: 'Kaution',
                quantity: 1.0,
                unitPriceGrossCents: 100000,
                taxRateBp: 0,
                taxCategory: TaxCategory::EXEMPT,
                taxExemptionReason: 'Durchlaufender Posten',
                priceIncludesTax: false,
            ),
        ]);

        [$sieben, $neunzehn, $befreit] = $totals->groups;

        // 1.232,00 + 1.987,50 netto, darauf 7 %.
        self::assertSame(321950, $sieben->netCents);
        self::assertSame(22537, $sieben->taxCents);

        self::assertSame(12000, $neunzehn->netCents);
        self::assertSame(2280, $neunzehn->taxCents);

        // Steuerfreies bleibt unberuehrt: mit 0 % aendert das Hochrechnen nichts.
        self::assertSame(100000, $befreit->netCents);
        self::assertSame(0, $befreit->taxCents);

        self::assertSame(458767, $totals->grossTotalCents);
        self::assertSame('4.587,67 €', Format::euro($totals->grossTotalCents));
    }

    /**
     * Der Umschalter darf die Bruttorechnung nicht anfassen: ohne Angabe
     * bleibt es beim bisherigen Verhalten.
     */
    public function testBruttoBleibtDerStandard(): void
    {
        $brutto = new LineItem(LineItem::KIND_CLEANING, 'Endreinigung', 1.0, 11900, 1900);

        self::assertTrue($brutto->priceIncludesTax);
        self::assertSame(11900, $brutto->grossCents());
        self::assertSame(10000, Calculator::compute([$brutto])->netTotalCents);
    }

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

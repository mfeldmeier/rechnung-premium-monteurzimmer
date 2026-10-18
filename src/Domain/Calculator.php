<?php

declare(strict_types=1);

namespace Pmz\Domain;

use InvalidArgumentException;

/**
 * Brutto rein, Netto und Steuer raus.
 *
 * Gerundet wird je Steuergruppe, nicht je Zeile. Zeilenweises Runden laesst die
 * Summe vom eingegebenen Brutto abweichen und bricht EN16931 BR-CO-17
 * (Gruppensteuer muss exakt Gruppennetto mal Satz sein). Das Gruppennetto wird
 * anschliessend verlustfrei auf die Zeilen verteilt, damit BT-131 je Zeile
 * gefuellt ist und die Zeilensumme exakt das Gruppennetto ergibt.
 */
final class Calculator
{
    /** @param LineItem[] $items */
    public static function compute(array $items): Totals
    {
        $lineGross = [];
        foreach ($items as $i => $item) {
            $lineGross[$i] = $item->grossCents();
        }

        /** @var array<string,array{category:string,rateBp:int,reason:?string,indexes:int[]}> $buckets */
        $buckets = [];
        foreach ($items as $i => $item) {
            $rateBp = $item->effectiveRateBp();
            $key    = $item->taxCategory . ':' . $rateBp;

            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'category' => $item->taxCategory,
                    'rateBp'   => $rateBp,
                    'reason'   => $item->taxExemptionReason,
                    'indexes'  => [],
                ];
            }

            $buckets[$key]['reason'] ??= $item->taxExemptionReason;
            $buckets[$key]['indexes'][] = $i;
        }

        $lineNet    = [];
        $groups     = [];
        $netTotal   = 0;
        $taxTotal   = 0;
        $grossTotal = 0;

        foreach ($buckets as $bucket) {
            $groupGross = 0;
            $weights    = [];
            foreach ($bucket['indexes'] as $i) {
                $groupGross += $lineGross[$i];
                $weights[]   = $lineGross[$i];
            }

            $groupNet = self::netFromGross($groupGross, $bucket['rateBp']);
            $groupTax = $groupGross - $groupNet;

            foreach (self::allocate($groupNet, $weights) as $position => $share) {
                $lineNet[$bucket['indexes'][$position]] = $share;
            }

            $groups[] = new TaxGroup(
                category: $bucket['category'],
                rateBp: $bucket['rateBp'],
                netCents: $groupNet,
                taxCents: $groupTax,
                grossCents: $groupGross,
                exemptionReason: $bucket['reason'],
            );

            $netTotal   += $groupNet;
            $taxTotal   += $groupTax;
            $grossTotal += $groupGross;
        }

        ksort($lineNet);

        // Besteuerte Gruppen zuerst, darin nach Satz aufsteigend - so liest es
        // sich auf dem PDF wie gewohnt: erst 7 %, dann 19 %, dann Befreites.
        usort($groups, static function (TaxGroup $a, TaxGroup $b): int {
            $aStandard = $a->category === TaxCategory::STANDARD ? 0 : 1;
            $bStandard = $b->category === TaxCategory::STANDARD ? 0 : 1;

            return [$aStandard, $a->rateBp, $a->category] <=> [$bStandard, $b->rateBp, $b->category];
        });

        return new Totals($lineGross, $lineNet, $groups, $netTotal, $taxTotal, $grossTotal);
    }

    public static function netFromGross(int $grossCents, int $rateBp): int
    {
        if ($rateBp === 0) {
            return $grossCents;
        }

        return (int) round($grossCents * 10000 / (10000 + $rateBp));
    }

    /**
     * Verteilt $total nach Gewichten, Rest nach groesstem Nachkommaanteil.
     * Die Rueckgabe summiert sich exakt auf $total.
     *
     * @param  int[] $weights
     * @return int[]
     */
    public static function allocate(int $total, array $weights): array
    {
        if ($total < 0) {
            throw new InvalidArgumentException('Negative Betraege gehoeren in eine Gutschrift, nicht in die Verteilung.');
        }

        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $sum = array_sum($weights);
        if ($sum === 0) {
            $out    = array_fill(0, $count, 0);
            $out[0] = $total;

            return $out;
        }

        $out        = [];
        $remainders = [];
        $assigned   = 0;

        foreach ($weights as $k => $weight) {
            $exact          = $total * $weight / $sum;
            $floor          = (int) floor($exact);
            $out[$k]        = $floor;
            $remainders[$k] = $exact - $floor;
            $assigned      += $floor;
        }

        $rest = $total - $assigned;
        arsort($remainders);

        foreach (array_keys($remainders) as $k) {
            if ($rest <= 0) {
                break;
            }
            $out[$k]++;
            $rest--;
        }

        ksort($out);

        return $out;
    }
}

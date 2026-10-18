<?php

declare(strict_types=1);

namespace Pmz\Domain;

use PDO;
use Pmz\Support\Db;
use RuntimeException;

/**
 * Vergibt die fortlaufende Rechnungsnummer.
 *
 * Die Nummer entsteht erst beim Finalisieren, nicht beim Anlegen des Entwurfs -
 * sonst reisst jeder verworfene Entwurf eine Luecke in den Nummernkreis, und
 * Luecken sind genau das, was eine Betriebspruefung beanstandet.
 */
final class NumberGenerator
{
    public function __construct(
        private readonly string $format = '{seq}/{year}',
        private readonly bool $resetYearly = true,
    ) {
    }

    public static function formatNumber(string $format, int $seq, int $year): string
    {
        return strtr($format, [
            '{seq}'   => (string) $seq,
            '{seq3}'  => str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            '{seq4}'  => str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
            '{year}'  => (string) $year,
            '{year2}' => substr((string) $year, -2),
        ]);
    }

    /**
     * Muss innerhalb einer bereits offenen Transaktion laufen: die Zeilensperre
     * aus SELECT ... FOR UPDATE haelt nur bis zum Commit. Ohne Transaktion
     * koennten zwei gleichzeitige Finalisierungen dieselbe Nummer ziehen.
     *
     * @return array{number:string,seq:int,year:int}
     */
    public function allocate(PDO $pdo, int $year): array
    {
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Nummernvergabe ohne offene Transaktion ist nicht sicher.');
        }

        $table       = Db::table('number_counters');
        $counterYear = $this->resetYearly ? $year : 0;

        $select = $pdo->prepare("SELECT `last_seq` FROM {$table} WHERE `number_year` = ? FOR UPDATE");
        $select->execute([$counterYear]);
        $last = $select->fetchColumn();

        if ($last === false) {
            $pdo->prepare(
                "INSERT INTO {$table} (`number_year`, `last_seq`) VALUES (?, 0)
                 ON DUPLICATE KEY UPDATE `last_seq` = `last_seq`"
            )->execute([$counterYear]);

            $select->execute([$counterYear]);
            $last = $select->fetchColumn();
        }

        $seq = (int) $last + 1;
        $pdo->prepare("UPDATE {$table} SET `last_seq` = ? WHERE `number_year` = ?")
            ->execute([$seq, $counterYear]);

        return [
            'number' => self::formatNumber($this->format, $seq, $year),
            'seq'    => $seq,
            'year'   => $year,
        ];
    }
}

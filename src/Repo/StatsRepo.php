<?php

declare(strict_types=1);

namespace Pmz\Repo;

use Pmz\Support\Db;

/**
 * Aggregate fuer das Dashboard. Bewusst getrennt von InvoiceRepo: dort gibt es
 * nur Einzelsaetze und Seiten, hier ausschliesslich Summen.
 */
final class StatsRepo
{
    /**
     * Stornierte Rechnungen stehen auf status = 'cancelled' und fallen damit
     * bereits aus der Umsatzsumme. Die zugehoerige Gutschrift wird deshalb
     * NICHT zusaetzlich abgezogen - sonst zaehlt die Stornierung doppelt.
     *
     * @return array{
     *     invoice_count:int, net_cents:int, tax_cents:int, gross_cents:int,
     *     draft_count:int, cancelled_count:int, credit_count:int
     * }
     */
    public function yearSummary(int $year): array
    {
        $table = Db::table('invoices');

        $stmt = Db::pdo()->prepare(
            "SELECT
                COALESCE(SUM(`status` = 'final' AND `doc_type` = 'invoice'), 0)      AS invoice_count,
                COALESCE(SUM(`status` = 'draft'), 0)                                 AS draft_count,
                COALESCE(SUM(`status` = 'cancelled'), 0)                             AS cancelled_count,
                COALESCE(SUM(`status` = 'final' AND `doc_type` = 'credit_note'), 0)  AS credit_count,
                COALESCE(SUM(CASE WHEN `status` = 'final' AND `doc_type` = 'invoice'
                    THEN `net_total_cents` ELSE 0 END), 0)                           AS net_cents,
                COALESCE(SUM(CASE WHEN `status` = 'final' AND `doc_type` = 'invoice'
                    THEN `tax_total_cents` ELSE 0 END), 0)                           AS tax_cents,
                COALESCE(SUM(CASE WHEN `status` = 'final' AND `doc_type` = 'invoice'
                    THEN `gross_total_cents` ELSE 0 END), 0)                         AS gross_cents
             FROM {$table}
             WHERE YEAR(`invoice_date`) = ?"
        );
        $stmt->execute([$year]);

        $row = $stmt->fetch() ?: [];

        return [
            'invoice_count'   => (int) ($row['invoice_count'] ?? 0),
            'draft_count'     => (int) ($row['draft_count'] ?? 0),
            'cancelled_count' => (int) ($row['cancelled_count'] ?? 0),
            'credit_count'    => (int) ($row['credit_count'] ?? 0),
            'net_cents'       => (int) ($row['net_cents'] ?? 0),
            'tax_cents'       => (int) ($row['tax_cents'] ?? 0),
            'gross_cents'     => (int) ($row['gross_cents'] ?? 0),
        ];
    }

    /** @return list<int> Jahre mit Rechnungen, absteigend - fuer den Filter. */
    public function years(): array
    {
        $table = Db::table('invoices');
        $rows  = Db::pdo()
            ->query("SELECT DISTINCT YEAR(`invoice_date`) AS y FROM {$table} ORDER BY y DESC")
            ->fetchAll();

        return array_map(static fn (array $row): int => (int) $row['y'], $rows);
    }
}

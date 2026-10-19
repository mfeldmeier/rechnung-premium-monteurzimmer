<?php
/**
 * @var int                              $year
 * @var array<string,int>                $summary
 * @var array<int,array<string,mixed>>   $recent
 */
?>
<div class="pagehead">
    <h1>Übersicht <?= e($year) ?></h1>
    <a class="btn btn-primary" href="/rechnungen/neu">Neue Rechnung</a>
</div>

<section class="tiles">
    <div class="tile">
        <span class="tile-label">Rechnungen final</span>
        <strong class="tile-value"><?= e($summary['invoice_count']) ?></strong>
    </div>
    <div class="tile">
        <span class="tile-label">Netto</span>
        <strong class="tile-value"><?= e(eur($summary['net_cents'])) ?></strong>
    </div>
    <div class="tile">
        <span class="tile-label">Umsatzsteuer</span>
        <strong class="tile-value"><?= e(eur($summary['tax_cents'])) ?></strong>
    </div>
    <div class="tile tile--accent">
        <span class="tile-label">Brutto</span>
        <strong class="tile-value"><?= e(eur($summary['gross_cents'])) ?></strong>
    </div>
    <div class="tile">
        <span class="tile-label">Entwürfe</span>
        <strong class="tile-value"><?= e($summary['draft_count']) ?></strong>
    </div>
    <div class="tile">
        <span class="tile-label">Storniert</span>
        <strong class="tile-value"><?= e($summary['cancelled_count']) ?></strong>
    </div>
</section>

<section class="card">
    <div class="pagehead pagehead--inner">
        <h2>Zuletzt bearbeitet</h2>
        <a href="/rechnungen">Alle Rechnungen</a>
    </div>

    <?php if ($recent === []): ?>
        <p class="muted">Noch keine Rechnungen vorhanden.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="grid">
            <thead>
            <tr>
                <th>Nummer</th>
                <th>Datum</th>
                <th>Kunde</th>
                <th>Status</th>
                <th class="num">Brutto</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $row): ?>
                <tr>
                    <td><?= e($row['number'] ?? '—') ?></td>
                    <td><?= e(dmy((string) $row['invoice_date'])) ?></td>
                    <td><?= e(customerLine($row['customer'])) ?></td>
                    <td><span class="badge badge--<?= e($row['status']) ?>"><?= e(statusLabel((string) $row['status'])) ?></span></td>
                    <td class="num"><?= e(eur((int) $row['gross_total_cents'])) ?></td>
                    <td class="num"><a href="/rechnungen/<?= e($row['id']) ?>">öffnen</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</section>

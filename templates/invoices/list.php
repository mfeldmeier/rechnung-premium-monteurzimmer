<?php
/**
 * @var array{status:string,year:int,q:string} $filter
 * @var array<int,array<string,mixed>>         $rows
 * @var int                                    $total
 * @var int                                    $page
 * @var int                                    $lastPage
 * @var list<int>                              $years
 */
$keep = ['status' => $filter['status'], 'jahr' => $filter['year'], 'q' => $filter['q']];
?>
<div class="pagehead">
    <h1>Rechnungen</h1>
    <a class="btn btn-primary" href="/rechnungen/neu">Neue Rechnung</a>
</div>

<form class="filterbar" method="get" action="/rechnungen">
    <label>
        Status
        <select name="status">
            <option value="">alle</option>
            <?php foreach (['draft', 'final', 'cancelled'] as $status): ?>
                <option value="<?= e($status) ?>"<?= $filter['status'] === $status ? ' selected' : '' ?>>
                    <?= e(statusLabel($status)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>
        Jahr
        <select name="jahr">
            <option value="">alle</option>
            <?php foreach ($years as $year): ?>
                <option value="<?= e($year) ?>"<?= $filter['year'] === $year ? ' selected' : '' ?>><?= e($year) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label class="grow">
        Suche
        <input type="search" name="q" value="<?= e($filter['q']) ?>" placeholder="Nummer oder Kunde">
    </label>

    <button type="submit" class="btn">Filtern</button>
    <a class="btn btn-plain" href="/rechnungen">Zurücksetzen</a>
</form>

<section class="card">
    <?php if ($rows === []): ?>
        <p class="muted">Keine Rechnungen gefunden.</p>
    <?php else: ?>
        <div class="table-scroll">
        <table class="grid">
            <thead>
            <tr>
                <th>Nummer</th>
                <th>Datum</th>
                <th>Kunde</th>
                <th>Art</th>
                <th>Status</th>
                <th class="num">Netto</th>
                <th class="num">Brutto</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><a href="/rechnungen/<?= e($row['id']) ?>"><?= e($row['number'] ?? 'Entwurf #' . $row['id']) ?></a></td>
                    <td><?= e(dmy((string) $row['invoice_date'])) ?></td>
                    <td><?= e(customerLine($row['customer'])) ?></td>
                    <td><?= $row['doc_type'] === 'credit_note' ? 'Storno' : 'Rechnung' ?></td>
                    <td><span class="badge badge--<?= e($row['status']) ?>"><?= e(statusLabel((string) $row['status'])) ?></span></td>
                    <td class="num"><?= e(eur((int) $row['net_total_cents'])) ?></td>
                    <td class="num"><?= e(eur((int) $row['gross_total_cents'])) ?></td>
                    <td class="num">
                        <?php if ($row['status'] !== 'draft'): ?>
                            <a href="/rechnungen/<?= e($row['id']) ?>/pdf">PDF</a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <nav class="pager">
            <?php if ($page > 1): ?>
                <a class="btn btn-plain" href="<?= e(urlWith('/rechnungen', $keep + ['seite' => $page - 1])) ?>">Zurück</a>
            <?php endif; ?>

            <span class="muted">Seite <?= e($page) ?> von <?= e($lastPage) ?> · <?= e($total) ?> Einträge</span>

            <?php if ($page < $lastPage): ?>
                <a class="btn btn-plain" href="<?= e(urlWith('/rechnungen', $keep + ['seite' => $page + 1])) ?>">Weiter</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>

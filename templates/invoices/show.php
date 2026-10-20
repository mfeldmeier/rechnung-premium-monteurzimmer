<?php
/**
 * Finalisierte Rechnungen werden nur noch gelesen - kein einziges Formularfeld.
 *
 * @var array<string,mixed> $invoice
 */
$customer = $invoice['customer'];
$isCredit = $invoice['doc_type'] === 'credit_note';
?>
<div class="pagehead">
    <h1>
        <?= $isCredit ? 'Storno' : 'Rechnung' ?> <?= e($invoice['number']) ?>
        <span class="badge badge--<?= e($invoice['status']) ?>"><?= e(statusLabel((string) $invoice['status'])) ?></span>
    </h1>
    <div class="actions">
        <a class="btn" href="/rechnungen/<?= e($invoice['id']) ?>/pdf">PDF herunterladen</a>
        <a class="btn btn-plain" href="/rechnungen">Zur Liste</a>
    </div>
</div>

<section class="card cols">
    <div>
        <h2>Kunde</h2>
        <p>
            <?php if (($invoice['customer_freeform'] ?? null) !== null): ?>
                <?= nl2br(e($invoice['customer_freeform'])) ?>
            <?php else: ?>
                <?php foreach (['company', 'contact_name', 'street'] as $field): ?>
                    <?php if (($customer[$field] ?? '') !== ''): ?>
                        <?= e($customer[$field]) ?><br>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?= e(trim(($customer['postcode'] ?? '') . ' ' . ($customer['city'] ?? ''))) ?>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <h2>Eckdaten</h2>
        <dl class="kv">
            <dt>Rechnungsdatum</dt><dd><?= e(dmy((string) $invoice['invoice_date'])) ?></dd>
            <?php if ($invoice['service_from'] !== null && $invoice['service_to'] !== null): ?>
                <dt>Leistungszeitraum</dt>
                <dd><?= e(dmy($invoice['service_from'])) ?> – <?= e(dmy($invoice['service_to'])) ?></dd>
            <?php endif; ?>
            <dt>Betreff</dt><dd><?= e($invoice['subject']) ?></dd>
            <dt>Preisbasis</dt>
            <dd><?= ($invoice['prices_include_tax'] ?? true) ? 'inkl. MwSt (brutto erfasst)' : 'zzgl. MwSt (netto erfasst)' ?></dd>
            <?php if ($invoice['finalized_at'] !== null): ?>
                <dt>Finalisiert</dt><dd><?= e($invoice['finalized_at']) ?></dd>
            <?php endif; ?>
            <?php if ($invoice['cancels_invoice_id'] !== null): ?>
                <dt>Storno zu</dt>
                <dd>
                    <a href="/rechnungen/<?= e($invoice['cancels_invoice_id']) ?>">
                        <?= e($invoice['cancels_invoice_number'] ?? ('Rechnung #' . $invoice['cancels_invoice_id'])) ?>
                    </a>
                </dd>
                <dt>Grund</dt><dd><?= e($invoice['cancel_reason']) ?></dd>
            <?php endif; ?>
        </dl>
    </div>
</section>

<section class="card">
    <h2>Positionen</h2>
    <div class="table-scroll">
    <table class="grid">
        <thead>
        <tr>
            <th>Beschreibung</th>
            <th>Zeitraum</th>
            <th class="num">Betten/Nächte</th>
            <th class="num">Menge</th>
            <th class="num">Einzelpreis</th>
            <th class="num">Steuer</th>
            <th class="num">Brutto</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($invoice['items'] as $item): ?>
            <tr>
                <td><?= e($item['description']) ?></td>
                <td>
                    <?php if ($item['date_from'] !== null && $item['date_to'] !== null): ?>
                        <?= e(dmy($item['date_from'])) ?> – <?= e(dmy($item['date_to'])) ?>
                    <?php endif; ?>
                </td>
                <td class="num">
                    <?php if ($item['beds'] !== null): ?>
                        <?= e($item['beds']) ?>/<?= e($item['nights']) ?>
                    <?php endif; ?>
                </td>
                <td class="num"><?= e(rtrim(rtrim(number_format($item['quantity'], 2, ',', '.'), '0'), ',')) ?></td>
                <td class="num"><?= e(eur($item['unit_price_gross_cents'])) ?></td>
                <td class="num">
                    <?= $item['tax_category'] === 'S' ? e(pct($item['tax_rate_bp'])) : 'steuerfrei' ?>
                </td>
                <td class="num"><?= e(eur($item['line_gross_cents'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>

<section class="card card--narrow">
    <h2>Summen</h2>
    <table class="totals">
        <?php foreach ($invoice['tax_breakdown'] as $group): ?>
            <tr>
                <th>
                    Netto
                    <?= $group['category'] === 'S' ? e(pct((int) $group['rate_bp'])) : 'steuerfrei' ?>
                </th>
                <td class="num"><?= e(eur((int) $group['net_cents'])) ?></td>
            </tr>
            <?php if ((int) $group['tax_cents'] !== 0): ?>
                <tr>
                    <th>zzgl. <?= e(pct((int) $group['rate_bp'])) ?> USt</th>
                    <td class="num"><?= e(eur((int) $group['tax_cents'])) ?></td>
                </tr>
            <?php endif; ?>
            <?php if (($group['exemption_reason'] ?? null) !== null): ?>
                <tr>
                    <td colspan="2" class="muted small"><?= e($group['exemption_reason']) ?></td>
                </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <tr class="totals-sum">
            <th>Gesamt brutto</th>
            <td class="num"><?= e(eur((int) $invoice['gross_total_cents'])) ?></td>
        </tr>
    </table>
</section>

<section class="card">
    <h2>Hinweise</h2>
    <p><?= nl2br(e($invoice['payment_terms_text'])) ?></p>
    <p><?= nl2br(e($invoice['closing_text'])) ?></p>
</section>

<?php if ($invoice['status'] === 'final' && !$isCredit): ?>
    <section class="card danger">
        <h2>Stornieren</h2>
        <p class="muted">
            Eine finalisierte Rechnung wird nicht geändert, sondern durch eine Gutschrift mit eigener
            Nummer ausgeglichen. Das Original bleibt erhalten und wird als storniert markiert.
        </p>
        <form method="post" action="/rechnungen/<?= e($invoice['id']) ?>/stornieren" class="row"
              data-confirm="Diese Rechnung wirklich stornieren? Das lässt sich nicht rückgängig machen.">
            <?= csrfField() ?>
            <label class="grow">
                Stornogrund
                <input type="text" name="cancel_reason" required maxlength="255" placeholder="z. B. Buchung storniert">
            </label>
            <button type="submit" class="btn btn-danger">Stornieren</button>
        </form>
    </section>
<?php endif; ?>

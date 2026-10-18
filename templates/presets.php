<?php
/**
 * @var array<int,array<string,mixed>> $rows
 * @var array<string,string>           $kinds
 * @var array<string,string>           $taxes
 */
?>
<div class="pagehead">
    <h1>Bausteine</h1>
</div>

<p class="muted">
    Jeder aktive Baustein erscheint im Rechnungseditor als Knopf. Ein Klick fügt eine Zeile
    mit diesen Vorgabewerten ein. Platzhalter <code>{apartment}</code> wird durch die gewählte
    Wohnung ersetzt.
</p>

<section class="card">
    <?php foreach ($rows as $row): ?>
        <?php $tax = $row['tax_category'] . '|' . (int) $row['tax_rate_bp']; ?>
        <form method="post" action="/bausteine" class="stack inline-form<?= (int) $row['is_active'] === 1 ? '' : ' is-inactive' ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= e($row['id']) ?>">

            <div class="row">
                <label class="grow">Beschriftung
                    <input type="text" name="label" value="<?= e($row['label']) ?>" maxlength="100" required>
                </label>
                <label>Art
                    <select name="kind">
                        <?php foreach ($kinds as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $row['kind'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Steuer
                    <select name="tax">
                        <?php foreach ($taxes as $value => $label): ?>
                            <option value="<?= e($value) ?>"<?= $tax === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="narrow">Reihenfolge
                    <input type="number" name="sort_order" value="<?= e($row['sort_order']) ?>" step="10">
                </label>
            </div>

            <div class="row">
                <label class="grow">Textvorlage
                    <input type="text" name="description_template" value="<?= e($row['description_template']) ?>" maxlength="255">
                </label>
                <label class="narrow">Vorgabepreis brutto
                    <input type="text" name="default_price" inputmode="decimal" placeholder="0,00"
                           value="<?= e($row['default_unit_price_gross_cents'] !== null
                               ? number_format((int) $row['default_unit_price_gross_cents'] / 100, 2, ',', '')
                               : '') ?>">
                </label>
                <label class="check">
                    <input type="checkbox" name="price_is_manual" value="1"<?= (int) $row['price_is_manual'] === 1 ? ' checked' : '' ?>>
                    Preis immer manuell
                </label>
                <label class="check">
                    <input type="checkbox" name="is_active" value="1"<?= (int) $row['is_active'] === 1 ? ' checked' : '' ?>>
                    aktiv
                </label>
            </div>

            <div class="row">
                <label class="grow">Begründung bei Steuerfreiheit
                    <input type="text" name="tax_exemption_reason" value="<?= e($row['tax_exemption_reason']) ?>" maxlength="255">
                </label>
                <button type="submit" class="btn">Speichern</button>
            </div>
        </form>
    <?php endforeach; ?>
</section>

<section class="card">
    <h2>Neuer Baustein</h2>
    <form method="post" action="/bausteine" class="stack">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="is_active" value="1">

        <div class="row">
            <label class="grow">Beschriftung <input type="text" name="label" maxlength="100" required></label>
            <label>Art
                <select name="kind">
                    <?php foreach ($kinds as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Steuer
                <select name="tax">
                    <?php foreach ($taxes as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="narrow">Reihenfolge <input type="number" name="sort_order" value="0" step="10"></label>
        </div>

        <div class="row">
            <label class="grow">Textvorlage <input type="text" name="description_template" maxlength="255"></label>
            <label class="narrow">Vorgabepreis brutto <input type="text" name="default_price" inputmode="decimal" placeholder="0,00"></label>
            <label class="check"><input type="checkbox" name="price_is_manual" value="1"> Preis immer manuell</label>
        </div>

        <div class="row">
            <label class="grow">Begründung bei Steuerfreiheit <input type="text" name="tax_exemption_reason" maxlength="255"></label>
            <button type="submit" class="btn btn-primary">Anlegen</button>
        </div>
    </form>
</section>

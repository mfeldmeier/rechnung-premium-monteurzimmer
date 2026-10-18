<?php

declare(strict_types=1);

namespace Pmz\Repo;

use Pmz\Support\Db;

final class PresetRepo
{
    public function allActive(): array
    {
        $table = Db::table('item_presets');

        return Db::pdo()
            ->query("SELECT * FROM {$table} WHERE `is_active` = 1 ORDER BY `sort_order`, `label`")
            ->fetchAll();
    }

    public function all(): array
    {
        $table = Db::table('item_presets');

        return Db::pdo()
            ->query("SELECT * FROM {$table} ORDER BY `is_active` DESC, `sort_order`, `label`")
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $table = Db::table('item_presets');
        $stmt  = Db::pdo()->prepare("SELECT * FROM {$table} WHERE `id` = ?");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $table = Db::table('item_presets');
        Db::pdo()->prepare(
            "INSERT INTO {$table}
                (`label`, `kind`, `description_template`, `default_unit_price_gross_cents`,
                 `price_is_manual`, `tax_rate_bp`, `tax_category`, `tax_exemption_reason`, `sort_order`, `is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
        )->execute($this->values($data));

        return (int) Db::pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $table    = Db::table('item_presets');
        $values   = $this->values($data);
        $values[] = (int) ($data['is_active'] ?? 1);
        $values[] = $id;

        Db::pdo()->prepare(
            "UPDATE {$table} SET
                `label` = ?, `kind` = ?, `description_template` = ?, `default_unit_price_gross_cents` = ?,
                `price_is_manual` = ?, `tax_rate_bp` = ?, `tax_category` = ?, `tax_exemption_reason` = ?,
                `sort_order` = ?, `is_active` = ?
             WHERE `id` = ?"
        )->execute($values);
    }

    public function deactivate(int $id): void
    {
        $table = Db::table('item_presets');
        Db::pdo()->prepare("UPDATE {$table} SET `is_active` = 0 WHERE `id` = ?")->execute([$id]);
    }

    private function values(array $data): array
    {
        $price = $data['default_unit_price_gross_cents'] ?? null;

        return [
            trim((string) $data['label']),
            (string) ($data['kind'] ?? 'custom'),
            (string) ($data['description_template'] ?? ''),
            ($price === null || $price === '') ? null : (int) $price,
            (int) (bool) ($data['price_is_manual'] ?? 0),
            (int) ($data['tax_rate_bp'] ?? 1900),
            (string) ($data['tax_category'] ?? 'S'),
            ($data['tax_exemption_reason'] ?? '') === '' ? null : (string) $data['tax_exemption_reason'],
            (int) ($data['sort_order'] ?? 0),
        ];
    }
}

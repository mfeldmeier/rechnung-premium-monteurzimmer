<?php

declare(strict_types=1);

namespace Pmz\Repo;

use Pmz\Support\Db;

final class ApartmentRepo
{
    public function allActive(): array
    {
        $table = Db::table('apartments');

        return Db::pdo()
            ->query("SELECT * FROM {$table} WHERE `is_active` = 1 ORDER BY `sort_order`, `name`")
            ->fetchAll();
    }

    public function all(): array
    {
        $table = Db::table('apartments');

        return Db::pdo()
            ->query("SELECT * FROM {$table} ORDER BY `is_active` DESC, `sort_order`, `name`")
            ->fetchAll();
    }

    public function find(int $id): ?array
    {
        $table = Db::table('apartments');
        $stmt  = Db::pdo()->prepare("SELECT * FROM {$table} WHERE `id` = ?");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Die Objektnummer aus der secra-Buchungsbestaetigung ("Objekt Nr. 310939")
     * zeigt auf genau eine Wohnung. Auch inaktive zaehlen: eine Buchung kann
     * noch laufen, wenn die Wohnung schon aus dem Angebot ist.
     */
    public function findByExternalRef(string $ref): ?array
    {
        $ref = trim($ref);

        if ($ref === '') {
            return null;
        }

        $table = Db::table('apartments');
        $stmt  = Db::pdo()->prepare(
            "SELECT * FROM {$table} WHERE `external_ref` = ? ORDER BY `is_active` DESC, `id` LIMIT 1"
        );
        $stmt->execute([$ref]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $table = Db::table('apartments');
        $stmt  = Db::pdo()->prepare(
            "INSERT INTO {$table} (`name`, `external_ref`, `beds_default`, `sort_order`, `is_active`) VALUES (?, ?, ?, ?, 1)"
        );
        $stmt->execute([
            trim((string) $data['name']),
            $this->nullIfBlank($data['external_ref'] ?? null),
            (int) ($data['beds_default'] ?? 1),
            (int) ($data['sort_order'] ?? 0),
        ]);

        return (int) Db::pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $table = Db::table('apartments');
        $stmt  = Db::pdo()->prepare(
            "UPDATE {$table} SET `name` = ?, `external_ref` = ?, `beds_default` = ?, `sort_order` = ?, `is_active` = ? WHERE `id` = ?"
        );
        $stmt->execute([
            trim((string) $data['name']),
            $this->nullIfBlank($data['external_ref'] ?? null),
            (int) ($data['beds_default'] ?? 1),
            (int) ($data['sort_order'] ?? 0),
            (int) ($data['is_active'] ?? 1),
            $id,
        ]);
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Wohnungen werden nie geloescht. Alte Rechnungen verweisen ueber
     * apartment_id darauf, und ein Loeschen wuerde die Historie zerreissen.
     */
    public function deactivate(int $id): void
    {
        $table = Db::table('apartments');
        Db::pdo()->prepare("UPDATE {$table} SET `is_active` = 0 WHERE `id` = ?")->execute([$id]);
    }
}

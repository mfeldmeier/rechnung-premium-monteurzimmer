<?php

declare(strict_types=1);

namespace Pmz\Repo;

use PDO;
use Pmz\Support\Db;

final class CustomerRepo
{
    private const FIELDS = [
        'company', 'contact_name', 'street', 'postcode', 'city',
        'country_code', 'email', 'phone', 'vat_id', 'notes',
    ];

    public function search(string $query, int $limit = 20): array
    {
        $table = Db::table('customers');
        $query = trim($query);

        if ($query === '') {
            $stmt = Db::pdo()->prepare(
                "SELECT * FROM {$table} WHERE `is_active` = 1 ORDER BY `company`, `contact_name` LIMIT ?"
            );
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        }

        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
        $stmt = Db::pdo()->prepare(
            "SELECT * FROM {$table}
             WHERE `is_active` = 1
               AND (`company` LIKE ? OR `contact_name` LIKE ? OR `city` LIKE ? OR `email` LIKE ?)
             ORDER BY `company`, `contact_name` LIMIT ?"
        );
        $stmt->bindValue(1, $like);
        $stmt->bindValue(2, $like);
        $stmt->bindValue(3, $like);
        $stmt->bindValue(4, $like);
        $stmt->bindValue(5, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $table = Db::table('customers');
        $stmt  = Db::pdo()->prepare("SELECT * FROM {$table} WHERE `id` = ?");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    public function create(array $data): int
    {
        $table   = Db::table('customers');
        $columns = implode(', ', array_map(static fn (string $f): string => "`{$f}`", self::FIELDS));
        $marks   = implode(', ', array_fill(0, count(self::FIELDS), '?'));

        Db::pdo()
            ->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$marks})")
            ->execute($this->values($data));

        return (int) Db::pdo()->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $table  = Db::table('customers');
        $assign = implode(', ', array_map(static fn (string $f): string => "`{$f}` = ?", self::FIELDS));

        $values   = $this->values($data);
        $values[] = $id;

        Db::pdo()->prepare("UPDATE {$table} SET {$assign} WHERE `id` = ?")->execute($values);
    }

    /** Kundenblock fuer den Datenvertrag; `notes` gehoert nicht auf die Rechnung. */
    public function snapshot(?array $row): array
    {
        return [
            'company'      => (string) ($row['company'] ?? ''),
            'contact_name' => (string) ($row['contact_name'] ?? ''),
            'street'       => (string) ($row['street'] ?? ''),
            'postcode'     => (string) ($row['postcode'] ?? ''),
            'city'         => (string) ($row['city'] ?? ''),
            'country_code' => (string) ($row['country_code'] ?? 'DE'),
            'email'        => (string) ($row['email'] ?? ''),
            'phone'        => (string) ($row['phone'] ?? ''),
            'vat_id'       => (string) ($row['vat_id'] ?? ''),
        ];
    }

    /** @return list<string> */
    private function values(array $data): array
    {
        $out = [];

        foreach (self::FIELDS as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            // E-Mail konsequent klein speichern, sonst findet die Suche denselben
            // Kunden spaeter nicht wieder.
            if ($field === 'email') {
                $value = strtolower($value);
            }
            if ($field === 'country_code') {
                $value = strtoupper($value !== '' ? $value : 'DE');
            }

            $out[] = $value;
        }

        return $out;
    }
}

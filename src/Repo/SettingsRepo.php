<?php

declare(strict_types=1);

namespace Pmz\Repo;

use Pmz\Support\Db;

final class SettingsRepo
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $table = Db::table('settings');
        $rows  = Db::pdo()->query("SELECT `setting_key`, `setting_value` FROM {$table}")->fetchAll();

        $this->cache = array_column($rows, 'setting_value', 'setting_key');

        return $this->cache;
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    /** @param array<string,string> $pairs */
    public function setMany(array $pairs): void
    {
        $table = Db::table('settings');
        $stmt  = Db::pdo()->prepare(
            "INSERT INTO {$table} (`setting_key`, `setting_value`) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)"
        );

        foreach ($pairs as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $this->cache = null;
    }

    /** Verkaeuferblock in der Form, die der Datenvertrag vorsieht. */
    public function seller(): array
    {
        $s = $this->all();

        return [
            'name'         => $s['seller_name']         ?? '',
            'owner'        => $s['seller_owner']        ?? '',
            'street'       => $s['seller_street']       ?? '',
            'postcode'     => $s['seller_postcode']     ?? '',
            'city'         => $s['seller_city']         ?? '',
            'country_code' => $s['seller_country_code'] ?? 'DE',
            'phone'        => $s['seller_phone']        ?? '',
            'email'        => $s['seller_email']        ?? '',
            'website'      => $s['seller_website']      ?? '',
            'tax_number'   => $s['seller_tax_number']   ?? '',
            'vat_id'       => $s['seller_vat_id']       ?? '',
            'bank_name'    => $s['bank_name']           ?? '',
            'bank_iban'    => $s['bank_iban']           ?? '',
            'bank_bic'     => $s['bank_bic']            ?? '',
        ];
    }
}

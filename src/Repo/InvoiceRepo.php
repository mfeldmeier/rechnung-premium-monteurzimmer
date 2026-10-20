<?php

declare(strict_types=1);

namespace Pmz\Repo;

use PDO;
use Pmz\Domain\RecipientBlock;
use Pmz\Support\Db;

final class InvoiceRepo
{
    public function __construct(
        private readonly SettingsRepo $settings = new SettingsRepo(),
        private readonly CustomerRepo $customers = new CustomerRepo(),
    ) {
    }

    public function findRaw(int $id): ?array
    {
        $table = Db::table('invoices');
        $stmt  = Db::pdo()->prepare("SELECT * FROM {$table} WHERE `id` = ?");
        $stmt->execute([$id]);

        return $stmt->fetch() ?: null;
    }

    /** Gibt es zu dieser Buchung schon eine Rechnung? Schuetzt vor Doppelimport. */
    public function findIdByBookingRef(string $bookingRef): ?int
    {
        $table = Db::table('invoices');
        $stmt  = Db::pdo()->prepare("SELECT `id` FROM {$table} WHERE `booking_ref` = ? LIMIT 1");
        $stmt->execute([$bookingRef]);

        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function itemsRaw(int $invoiceId): array
    {
        $table = Db::table('invoice_items');
        $stmt  = Db::pdo()->prepare("SELECT * FROM {$table} WHERE `invoice_id` = ? ORDER BY `sort_order`, `id`");
        $stmt->execute([$invoiceId]);

        return $stmt->fetchAll();
    }

    /**
     * Der Datenvertrag. PDF-Erzeugung, ZUGFeRD und Views lesen ausschliesslich
     * diese Struktur - nicht die Rohzeilen.
     *
     * Entwuerfe ziehen Kunden- und Verkaeuferdaten live aus den Stammdaten,
     * damit Korrekturen dort noch durchschlagen. Ab dem Finalisieren gilt der
     * eingefrorene Snapshot, sonst wuerde eine spaetere Adressaenderung alte
     * Rechnungen rueckwirkend umschreiben.
     */
    public function load(int $id): ?array
    {
        $row = $this->findRaw($id);
        if ($row === null) {
            return null;
        }

        $freeform = ($row['customer_freeform'] ?? '') !== '' ? (string) $row['customer_freeform'] : null;

        if ($row['status'] === 'draft') {
            // Im Freitextmodus steht die Anschrift im Block, nicht im Stamm.
            $customer = $freeform !== null
                ? RecipientBlock::parse($freeform)->snapshot
                : $this->customers->snapshot(
                    $row['customer_id'] !== null ? $this->customers->find((int) $row['customer_id']) : null,
                );
            $seller = $this->settings->seller();
        } else {
            $customer = $this->decode($row['customer_snapshot']);
            $seller   = $this->decode($row['seller_snapshot']);
        }

        return [
            'id'                 => (int) $row['id'],
            'status'             => (string) $row['status'],
            'doc_type'           => (string) $row['doc_type'],
            'number'             => $row['number'] !== null ? (string) $row['number'] : null,
            'invoice_date'       => (string) $row['invoice_date'],
            'service_from'       => $row['service_from'] !== null ? (string) $row['service_from'] : null,
            'service_to'         => $row['service_to'] !== null ? (string) $row['service_to'] : null,
            'subject'            => (string) $row['subject'],
            'customer_id'        => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'customer_freeform'  => $freeform,
            'booking_ref'        => ($row['booking_ref'] ?? '') !== '' ? (string) $row['booking_ref'] : null,
            'customer'           => $customer,
            'seller'             => $seller,
            'payment_terms_text' => (string) $row['payment_terms_text'],
            'closing_text'       => (string) ($row['closing_text'] ?? ''),
            // Rechnungen von vor Migration 004 waren ausnahmslos brutto.
            'prices_include_tax' => (int) ($row['prices_include_tax'] ?? 1) === 1,
            'items'              => array_map($this->mapItem(...), $this->itemsRaw($id)),
            'tax_breakdown'      => $this->decode($row['tax_breakdown']),
            'net_total_cents'    => (int) $row['net_total_cents'],
            'tax_total_cents'    => (int) $row['tax_total_cents'],
            'gross_total_cents'  => (int) $row['gross_total_cents'],
            'cancels_invoice_id' => $row['cancels_invoice_id'] !== null ? (int) $row['cancels_invoice_id'] : null,
            // Die Nummer der stornierten Rechnung, nicht nur ihre Id: eine
            // Korrektur muss nachvollziehbar sein, und "zu Rechnung 69/2026"
            // liest sich auf dem Papier besser als eine interne Id.
            'cancels_invoice_number' => $this->numberOf($row['cancels_invoice_id'] ?? null),
            'cancel_reason'      => $row['cancel_reason'] !== null ? (string) $row['cancel_reason'] : null,
            'pdf_filename'       => $row['pdf_filename'] !== null ? (string) $row['pdf_filename'] : null,
            'finalized_at'       => $row['finalized_at'] !== null ? (string) $row['finalized_at'] : null,
        ];
    }

    public function insert(array $row): int
    {
        $table   = Db::table('invoices');
        $columns = array_keys($row);
        $names   = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $columns));
        $marks   = implode(', ', array_fill(0, count($columns), '?'));

        Db::pdo()
            ->prepare("INSERT INTO {$table} ({$names}) VALUES ({$marks})")
            ->execute(array_values($row));

        return (int) Db::pdo()->lastInsertId();
    }

    public function update(int $id, array $row): void
    {
        if ($row === []) {
            return;
        }

        $table  = Db::table('invoices');
        $assign = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($row)));

        $values   = array_values($row);
        $values[] = $id;

        Db::pdo()->prepare("UPDATE {$table} SET {$assign} WHERE `id` = ?")->execute($values);
    }

    /** Positionen werden immer komplett ersetzt - Teil-Updates lohnen hier nicht. */
    public function replaceItems(int $invoiceId, array $items): void
    {
        $table = Db::table('invoice_items');
        Db::pdo()->prepare("DELETE FROM {$table} WHERE `invoice_id` = ?")->execute([$invoiceId]);

        $stmt = Db::pdo()->prepare(
            "INSERT INTO {$table}
                (`invoice_id`, `sort_order`, `kind`, `description`, `apartment_id`, `apartment_name`,
                 `date_from`, `date_to`, `beds`, `nights`, `quantity`, `unit_price_gross_cents`,
                 `price_mode`, `input_unit_price_cents`, `input_line_cents`,
                 `tax_rate_bp`, `tax_category`, `tax_exemption_reason`,
                 `line_gross_cents`, `line_net_cents`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach (array_values($items) as $position => $item) {
            $stmt->execute([
                $invoiceId,
                $position,
                $item['kind'],
                $item['description'],
                $item['apartment_id'] ?? null,
                $item['apartment_name'] ?? null,
                $item['date_from'] ?? null,
                $item['date_to'] ?? null,
                $item['beds'] ?? null,
                $item['nights'] ?? null,
                $item['quantity'],
                $item['unit_price_gross_cents'],
                $item['price_mode'] ?? 'unit',
                $item['input_unit_price_cents'] ?? $item['unit_price_gross_cents'],
                $item['input_line_cents'] ?? $item['line_gross_cents'],
                $item['tax_rate_bp'],
                $item['tax_category'],
                $item['tax_exemption_reason'] ?? null,
                $item['line_gross_cents'],
                $item['line_net_cents'],
            ]);
        }
    }

    /**
     * @param array{status?:string,year?:int,q?:string} $filter
     * @return array{rows:array,total:int}
     */
    public function paginate(array $filter, int $page = 1, int $perPage = 25): array
    {
        $invoices = Db::table('invoices');
        $where    = [];
        $params   = [];

        if (($filter['status'] ?? '') !== '') {
            $where[]  = '`status` = ?';
            $params[] = $filter['status'];
        }
        if ((int) ($filter['year'] ?? 0) > 0) {
            $where[]  = 'YEAR(`invoice_date`) = ?';
            $params[] = (int) $filter['year'];
        }
        if (trim((string) ($filter['q'] ?? '')) !== '') {
            $like    = '%' . str_replace(['%', '_'], ['\%', '\_'], trim((string) $filter['q'])) . '%';
            // Entwuerfe im Freitextmodus haben noch keinen Snapshot - ohne den
            // Block waeren sie ueber den Kundennamen nicht auffindbar.
            $where[] = '(`number` LIKE ? OR `customer_snapshot` LIKE ? OR `customer_freeform` LIKE ?)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $clause = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $countStmt = Db::pdo()->prepare("SELECT COUNT(*) FROM {$invoices} {$clause}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $page    = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset  = ($page - 1) * $perPage;

        $stmt = Db::pdo()->prepare(
            "SELECT * FROM {$invoices} {$clause}
             ORDER BY COALESCE(`finalized_at`, `created_at`) DESC, `id` DESC
             LIMIT ? OFFSET ?"
        );

        $i = 1;
        foreach ($params as $param) {
            $stmt->bindValue($i++, $param);
        }
        $stmt->bindValue($i++, $perPage, PDO::PARAM_INT);
        $stmt->bindValue($i, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(function (array $row): array {
            $row['customer'] = match (true) {
                $row['status'] !== 'draft'            => $this->decode($row['customer_snapshot']),
                ($row['customer_freeform'] ?? '') !== '' => RecipientBlock::parse((string) $row['customer_freeform'])->snapshot,
                $row['customer_id'] !== null          => $this->customers->snapshot($this->customers->find((int) $row['customer_id'])),
                default                               => $this->customers->snapshot(null),
            };

            return $row;
        }, $stmt->fetchAll());

        return ['rows' => $rows, 'total' => $total];
    }

    private function numberOf(mixed $invoiceId): ?string
    {
        if ($invoiceId === null) {
            return null;
        }

        $table = Db::table('invoices');
        $stmt  = Db::pdo()->prepare("SELECT `number` FROM {$table} WHERE `id` = ?");
        $stmt->execute([(int) $invoiceId]);

        $number = $stmt->fetchColumn();

        return $number === false || $number === null ? null : (string) $number;
    }

    private function mapItem(array $row): array
    {
        return [
            'kind'                   => (string) $row['kind'],
            'description'            => (string) $row['description'],
            'apartment_id'           => $row['apartment_id'] !== null ? (int) $row['apartment_id'] : null,
            'apartment_name'         => $row['apartment_name'] !== null ? (string) $row['apartment_name'] : null,
            'date_from'              => $row['date_from'] !== null ? (string) $row['date_from'] : null,
            'date_to'                => $row['date_to'] !== null ? (string) $row['date_to'] : null,
            'beds'                   => $row['beds'] !== null ? (int) $row['beds'] : null,
            'nights'                 => $row['nights'] !== null ? (int) $row['nights'] : null,
            'quantity'               => (float) $row['quantity'],
            'unit_price_gross_cents' => (int) $row['unit_price_gross_cents'],
            'price_mode'             => (string) ($row['price_mode'] ?? 'unit'),
            // Was im Editor stand. Vor Migration 004 gab es nur Brutto, dort
            // sind Eingabe und Bruttowert dasselbe.
            'input_unit_price_cents' => (int) ($row['input_unit_price_cents'] ?? $row['unit_price_gross_cents']),
            'input_line_cents'       => (int) ($row['input_line_cents'] ?? $row['line_gross_cents']),
            'tax_rate_bp'            => (int) $row['tax_rate_bp'],
            'tax_category'           => (string) $row['tax_category'],
            'tax_exemption_reason'   => $row['tax_exemption_reason'] !== null ? (string) $row['tax_exemption_reason'] : null,
            'line_gross_cents'       => (int) $row['line_gross_cents'],
            'line_net_cents'         => (int) $row['line_net_cents'],
        ];
    }

    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}

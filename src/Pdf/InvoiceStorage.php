<?php

declare(strict_types=1);

namespace Pmz\Pdf;

use Pmz\Support\Env;
use RuntimeException;

/** Legt die fertigen PDFs ausserhalb des Docroots ab und findet sie wieder. */
final class InvoiceStorage
{
    private const SUBDIR = 'invoices';

    public function store(array $invoice, string $pdfBytes): string
    {
        $directory = $this->directory();

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new RuntimeException("Ablageverzeichnis liess sich nicht anlegen: {$directory}");
        }

        $filename = self::filename($invoice);

        if (file_put_contents($directory . '/' . $filename, $pdfBytes) === false) {
            throw new RuntimeException("PDF liess sich nicht schreiben: {$filename}");
        }

        return $filename;
    }

    public function path(string $filename): string
    {
        return $this->directory() . '/' . basename($filename);
    }

    /** z.B. "Rechnung-69-2026-serdar24-intensivpflege.pdf" */
    public static function filename(array $invoice): string
    {
        $number = (string) ($invoice['number'] ?? '');
        $part   = $number !== ''
            ? str_replace('/', '-', $number)
            : 'Entwurf-' . ($invoice['id'] ?? '0');

        $customer = $invoice['customer'] ?? [];
        $name     = (string) ($customer['company'] ?? '') !== ''
            ? (string) $customer['company']
            : (string) ($customer['contact_name'] ?? '');

        $slug = self::slug($name);

        return 'Rechnung-' . $part . ($slug !== '' ? '-' . $slug : '') . '.pdf';
    }

    private function directory(): string
    {
        $configured = Env::get('STORAGE_PATH', 'storage');

        $base = str_starts_with($configured, '/')
            ? $configured
            : dirname(__DIR__, 2) . '/' . $configured;

        return rtrim($base, '/') . '/' . self::SUBDIR;
    }

    private static function slug(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), [
            'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
            'á' => 'a', 'à' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }
}

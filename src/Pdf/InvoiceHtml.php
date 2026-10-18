<?php

declare(strict_types=1);

namespace Pmz\Pdf;

use RuntimeException;
use Throwable;

/** Rendert das Rechnungs-Template zu HTML. Kein mPDF, damit das Layout testbar bleibt. */
final class InvoiceHtml
{
    private const TEMPLATE = __DIR__ . '/../../templates/pdf/invoice.php';
    private const LOGO     = __DIR__ . '/../../public/assets/logo.png';

    public function render(array $invoice): string
    {
        $logoSrc = $this->logoSrc();

        ob_start();

        try {
            // Die include-Variablen heissen genauso wie die @var-Angaben im Template.
            require self::TEMPLATE;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        $html = ob_get_clean();

        if ($html === false) {
            throw new RuntimeException('Das Rechnungs-Template lieferte keine Ausgabe.');
        }

        return $html;
    }

    /**
     * Logo als data:-URI statt als Pfad: mPDF muss dann weder das Dateisystem
     * noch einen Basispfad aufloesen, und der Renderer bleibt ortsunabhaengig.
     */
    private function logoSrc(): string
    {
        if (!is_readable(self::LOGO)) {
            return '';
        }

        $data = file_get_contents(self::LOGO);

        return $data === false ? '' : 'data:image/png;base64,' . base64_encode($data);
    }
}

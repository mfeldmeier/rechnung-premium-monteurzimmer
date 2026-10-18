<?php

declare(strict_types=1);

namespace Pmz\Pdf;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/** Erzeugt aus dem Rechnungs-Array die reinen PDF-Bytes - noch ohne ZUGFeRD-XML. */
final class PdfRenderer
{
    private readonly string $tempDir;

    public function __construct(?string $tempDir = null, private readonly InvoiceHtml $html = new InvoiceHtml())
    {
        $this->tempDir = $tempDir ?? sys_get_temp_dir();
    }

    public function render(array $invoice): string
    {
        $mpdf = new Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'tempDir'       => $this->tempDir,
            'margin_left'   => 20,
            'margin_right'  => 20,
            'margin_top'    => 15,
            'margin_bottom' => 30,
            'margin_footer' => 8,
        ]);

        // PDF/A ist Voraussetzung dafuer, dass horstoeko/zugferd das XML spaeter
        // normkonform als PDF/A-3 einbetten kann. PDFAauto laesst mPDF die noetigen
        // Korrekturen (Farbraum, Transparenz) selbst vornehmen, statt abzubrechen.
        $mpdf->PDFA        = true;
        $mpdf->PDFAauto    = true;
        $mpdf->PDFAversion = '3-B';

        // Das Template entscheidet ueber <watermarktext>; ohne Tag bleibt das wirkungslos.
        $mpdf->showWatermarkText = true;

        $mpdf->SetTitle($this->title($invoice));
        $mpdf->SetAuthor((string) ($invoice['seller']['name'] ?? ''));
        $mpdf->SetCreator((string) ($invoice['seller']['name'] ?? ''));

        $mpdf->WriteHTML($this->html->render($invoice));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function title(array $invoice): string
    {
        $number = $invoice['number'] ?? null;

        if ($number !== null && $number !== '') {
            return $invoice['doc_type'] === 'credit_note'
                ? 'Storno-Rechnung ' . $number
                : 'Rechnung ' . $number;
        }

        return 'Rechnungsentwurf ' . ($invoice['id'] ?? '');
    }
}

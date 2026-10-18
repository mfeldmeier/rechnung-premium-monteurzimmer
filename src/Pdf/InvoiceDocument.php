<?php

declare(strict_types=1);

namespace Pmz\Pdf;

use horstoeko\zugferd\ZugferdDocumentPdfMerger;

/** Setzt PDF und ZUGFeRD-XML zum hybriden PDF/A-3 zusammen. */
final class InvoiceDocument
{
    public function __construct(
        private readonly PdfRenderer $renderer = new PdfRenderer(),
        private readonly ZugferdBuilder $zugferd = new ZugferdBuilder(),
    ) {
    }

    public function createHybrid(array $invoice): string
    {
        $pdfBytes = $this->renderer->render($invoice);
        $xml      = $this->zugferd->buildXml($invoice);

        // Der Merger liest das Profil aus dem XML und haengt es unter dem dort
        // hinterlegten Namen an - fuer EN16931 ist das "factur-x.xml".
        return (new ZugferdDocumentPdfMerger($xml, $pdfBytes))
            ->generateDocument()
            ->downloadString();
    }
}

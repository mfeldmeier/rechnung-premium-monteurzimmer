<?php

declare(strict_types=1);

namespace Pmz\Import;

use DomainException;
use Pmz\Domain\Booking;
use Smalot\PdfParser\Parser;
use Throwable;

/** Holt den Text aus einer Buchungsbestaetigung und gibt ihn dem Parser. */
final class BookingPdfReader
{
    public function __construct(
        private readonly BookingParser $parser = new BookingParser(),
    ) {
    }

    public function fromFile(string $path): Booking
    {
        if (!is_readable($path)) {
            throw new DomainException("Datei nicht lesbar: {$path}");
        }

        return $this->parser->parse($this->text((string) file_get_contents($path)));
    }

    /** Fuer Anhaenge aus dem Postfach, die nie auf der Platte landen. */
    public function fromBytes(string $bytes): Booking
    {
        return $this->parser->parse($this->text($bytes));
    }

    private function text(string $bytes): string
    {
        try {
            return (new Parser())->parseContent($bytes)->getText();
        } catch (Throwable $e) {
            // Verschluesselte, beschaedigte oder eingescannte PDFs landen hier.
            throw new DomainException('Das PDF ist nicht lesbar: ' . $e->getMessage(), 0, $e);
        }
    }
}

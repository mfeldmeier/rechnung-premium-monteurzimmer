<?php

declare(strict_types=1);

namespace Pmz\Domain;

/** Eine Zeile der Steueraufschluesselung (EN16931 BG-23). */
final readonly class TaxGroup
{
    public function __construct(
        public string $category,
        public int $rateBp,
        public int $netCents,
        public int $taxCents,
        public int $grossCents,
        public ?string $exemptionReason = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'category'         => $this->category,
            'rate_bp'          => $this->rateBp,
            'net_cents'        => $this->netCents,
            'tax_cents'        => $this->taxCents,
            'gross_cents'      => $this->grossCents,
            'exemption_reason' => $this->exemptionReason,
        ];
    }
}

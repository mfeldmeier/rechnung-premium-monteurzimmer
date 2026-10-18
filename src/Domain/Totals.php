<?php

declare(strict_types=1);

namespace Pmz\Domain;

final readonly class Totals
{
    /**
     * @param array<int,int> $lineGrossCents nach Eingabereihenfolge indiziert
     * @param array<int,int> $lineNetCents   dito
     * @param TaxGroup[]     $groups
     */
    public function __construct(
        public array $lineGrossCents,
        public array $lineNetCents,
        public array $groups,
        public int $netTotalCents,
        public int $taxTotalCents,
        public int $grossTotalCents,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function breakdownToArray(): array
    {
        return array_map(static fn (TaxGroup $g): array => $g->toArray(), $this->groups);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use Carbon\CarbonImmutable;

/**
 * An advisory, immediately-stale view of stock: what was on hand where, as of
 * `asOf`, read in one statement. It takes no lock and reserves nothing.
 */
final class StockAvailabilitySnapshot
{
    /** @param array<int, list<StockBalance>> $balancesByItem keyed by item id */
    public function __construct(
        public readonly CarbonImmutable $asOf,
        public readonly array $balancesByItem,
    ) {}

    /** @return list<StockBalance> */
    public function balancesFor(int $itemId): array
    {
        return $this->balancesByItem[$itemId] ?? [];
    }
}

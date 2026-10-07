<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\StockMove;

/**
 * What the sole movement boundary actually did for an issue or transfer: the stock move and the allocation vector it used, captured
 * at posting rather than derived afterwards (ADR 0090 §5). AVCO persists no per-layer consumption, so this is the only complete
 * record of the vector for every valuation method.
 *
 * `allocations` is null when the call was an idempotent retry that found the move already posted — the vector was captured the
 * first time and a caller must read it from its own stored evidence, never guess. `uncoveredQty` is the part of an issue that no
 * layer covered (an authorised shortage), or the part of a transfer later consumed by negative-debt settlement at the destination;
 * zero for an ordinary posting. Adapters that cannot represent either refuse a non-zero value.
 */
final class PostedMovement
{
    /** @param list<StockLayerAllocation>|null $allocations */
    public function __construct(
        public readonly StockMove $move,
        public readonly ?array $allocations,
        public readonly string $uncoveredQty = '0.0000',
    ) {}

    public function wasReplayed(): bool
    {
        return $this->allocations === null;
    }
}

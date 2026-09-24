<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Models\ResourceRate;
use App\Modules\Inventory\Models\Item;
use Illuminate\Support\Carbon;

/**
 * "The most recent rate where effective_from <= the target date" — the
 * resolution rule docs/domain/multi-currency.md already documents for
 * exchange rates, applied here for the first time anywhere in the codebase.
 * Returns null rather than throwing when nothing resolves (a LABOUR/PLANT
 * item with no rate configured yet) — the caller decides whether that's an
 * error (see AssemblyComponent costing, which requires a rate_override in
 * that case) rather than this resolver assuming it always is one.
 */
final class ResolveResourceRate
{
    public function __invoke(Item $item, ?Carbon $asOf = null): ?ResourceRate
    {
        return ResourceRate::query()
            ->where('item_id', $item->id)
            ->where('effective_from', '<=', ($asOf ?? Carbon::today())->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }
}

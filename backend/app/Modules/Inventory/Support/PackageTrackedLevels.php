<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Illuminate\Support\Facades\DB;

/**
 * The sealed package levels a grouped item tracks (ADR 0092 §1a): fixed at activation, immutable, and the only definitions any evidence state of
 * the item may name afterwards. The database refuses anything else; this gives the callers a friendly message first.
 */
final class PackageTrackedLevels
{
    /** @return list<int>|null null when the item is not grouped (nothing is restricted) */
    public static function for(int $itemId): ?array
    {
        $activation = DB::table('items')->where('id', $itemId)->value('package_tracking_activation_id');
        if ($activation === null) {
            return null;
        }

        return DB::table('inventory_package_tracked_levels')->where('activation_id', $activation)->orderBy('item_packaging_id')->pluck('item_packaging_id')->map(fn ($id) => (int) $id)->all();
    }

    /** @param list<int> $packagingIds */
    public static function require(int $itemId, array $packagingIds, string $what): void
    {
        $tracked = self::for($itemId);
        if ($tracked === null) {
            return;
        }
        foreach ($packagingIds as $id) {
            if (! in_array($id, $tracked, true)) {
                throw new DomainInvariantException("This item does not track that package level, so {$what} cannot use it; record the tracked packages instead (changing tracked levels is a separate governed change).", 'item_packaging_id');
            }
        }
    }

    /**
     * Definitions closed over the level inside each, so tracking an outer package always tracks what it holds.
     *
     * @param  list<int>  $packagingIds
     * @return list<int>
     */
    public static function closure(array $packagingIds): array
    {
        $all = [];
        $queue = $packagingIds;
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($all[$id])) {
                continue;
            }
            $all[$id] = $id;
            $inner = DB::table('item_packagings')->where('id', $id)->value('inner_packaging_id');
            if ($inner !== null) {
                $queue[] = (int) $inner;
            }
        }
        sort($all);

        return $all;
    }
}

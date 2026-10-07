<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Illuminate\Support\Facades\DB;

/**
 * The one door through which stock of an item with grouped package tracking may change (ADR 0091 §3). A package-aware Action runs its own
 * posting call inside `run()`, which sets the transaction-local database flag the guard triggers look for and the matching in-process flag
 * the friendly PHP guards look for, and clears both straight afterwards. Everything else is refused: with a clear message here, and by the
 * database triggers even if a writer forgets to ask.
 */
final class PackageWriteScope
{
    /** @var array<int,int> */
    private static array $depth = [];

    public static function isAuthoritative(int $itemId): bool
    {
        return DB::table('items')->where('id', $itemId)->value('package_tracking_mode') === PackageTrackingMode::PackageBalance->value;
    }

    public static function active(int $itemId): bool
    {
        return (self::$depth[$itemId] ?? 0) > 0;
    }

    /**
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function run(?int $itemId, callable $callback): mixed
    {
        if ($itemId === null) {
            return $callback();
        }
        DB::select("SELECT set_config('erp.package_aware_item', ?, true)", [(string) $itemId]);
        self::$depth[$itemId] = (self::$depth[$itemId] ?? 0) + 1;
        try {
            return $callback();
        } finally {
            self::$depth[$itemId]--;
            if (self::$depth[$itemId] === 0) {
                unset(self::$depth[$itemId]);
                DB::select("SELECT set_config('erp.package_aware_item', '', true)");
            }
        }
    }

    /** Refuse, with an actionable message, a stock-changing operation that has no package-aware form for this item yet. */
    public static function refuse(int $itemId, string $operation, ?string $instead = null): void
    {
        if (self::isAuthoritative($itemId) && ! self::active($itemId)) {
            throw new DomainInvariantException(
                "This item uses grouped package tracking, so {$operation} is not available for it".($instead === null ? ' yet.' : "; {$instead}"),
                'package_tracking_mode',
                'PACKAGE_TRACKED_ITEM',
            );
        }
    }
}

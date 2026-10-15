<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Support\Quantity\QuantityContract;
use App\Support\Quantity\QuantityRefusal;
use App\Support\Quantity\QuantitySign;
use Illuminate\Database\Eloquent\Model;

/**
 * What an Item's increments, unit conversions and package definitions must satisfy (ADR 0142). The model hooks call these, so every writer — Actions,
 * imports, seeders — is held to the same rule, and the refusal is a stable reason code rather than a database error.
 *
 * The destination of a stocked item's quantities is the stock ledger (four places). An item that is not stocked is judged against a commercial line of the
 * same four places *today* because every transaction column is four places; keeping that choice here means a later service or technical quantity context
 * changes one method, not the ledger rule.
 */
final class ItemQuantityRules
{
    /** The destination contract of an item's quantities, before any of its own increments. */
    public static function destination(Item $item): QuantityContract
    {
        return $item->is_stocked ? QuantityContract::stockLedger() : QuantityContract::commercialLine(4);
    }

    /** The destination with the item's own increment applied. */
    public static function withIncrement(Item $item): QuantityContract
    {
        return self::destination($item)->withIncrement(self::raw($item, 'quantity_precision'));
    }

    /**
     * The value as it was assigned. The models cast these columns with `decimal:9`, which would silently round a finer value (0.0000000001 reads as zero)
     * before the rule saw it, so the rule reads the raw attribute.
     */
    private static function raw(Model $model, string $key): mixed
    {
        return $model->getAttributes()[$key] ?? null;
    }

    /** An item's stock-unit increment: positive and reachable by the destination. */
    public static function assertItem(Item $item): void
    {
        $increment = self::raw($item, 'quantity_precision');
        if ($increment !== null) {
            self::destination($item)->acceptIncrement($increment, 'quantity_precision');
        }
    }

    /** A pack unit's factor (evidence, nine places) and, when it has one, its increment — checked through the factor against the item. */
    public static function assertUnit(ItemQuantityUnit $unit): void
    {
        $item = Item::query()->find($unit->item_id);
        if ($item === null) {
            return; // the company-scope guard reports a missing or foreign item
        }
        QuantityContract::factor(self::raw($unit, 'factor'), 'factor');
        $increment = self::raw($unit, 'quantity_precision');
        if ($increment !== null) {
            self::withIncrement($item)->acceptTransactionIncrement($increment, self::raw($unit, 'factor'), 'quantity_precision');
        }
    }

    /** A package definition: content must be exact at stock precision and a multiple of the item increment; a package increment is checked through its content. */
    public static function assertPackaging(ItemPackaging $package): void
    {
        $item = Item::query()->find($package->item_id);
        if ($item === null) {
            return;
        }
        $destination = self::withIncrement($item);
        $destination->represent(self::raw($package, 'stock_quantity'), QuantitySign::Positive, 'stock_quantity');
        $increment = self::raw($package, 'quantity_precision');
        if ($increment !== null) {
            $destination->acceptTransactionIncrement($increment, self::raw($package, 'stock_quantity'), 'quantity_precision');
        }
    }

    /**
     * Changing an item's increment must not leave its own definitions unreachable: every active package's content and every pack unit's increment has to
     * remain a whole multiple of the new increment.
     */
    public static function assertIncrementFitsDefinitions(Item $item): void
    {
        if (self::raw($item, 'quantity_precision') === null || ! $item->exists) {
            return;
        }
        $destination = self::withIncrement($item);
        foreach (ItemPackaging::query()->where('item_id', $item->id)->where('active', true)->get() as $package) {
            try {
                $destination->represent($package->stock_quantity, QuantitySign::Positive, 'quantity_precision');
            } catch (DomainInvariantException) {
                throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INCOMPATIBLE,
                    "The increment does not fit package {$package->code}, which holds {$package->stock_quantity}.", 'quantity_precision');
            }
        }
        foreach (ItemQuantityUnit::query()->where('item_id', $item->id)->whereNotNull('quantity_precision')->get() as $unit) {
            try {
                $destination->acceptTransactionIncrement($unit->quantity_precision, $unit->factor, 'quantity_precision');
            } catch (DomainInvariantException) {
                throw QuantityRefusal::make(QuantityRefusal::INCREMENT_INCOMPATIBLE,
                    'The increment does not fit an existing pack-unit conversion of this item.', 'quantity_precision');
            }
        }
    }
}

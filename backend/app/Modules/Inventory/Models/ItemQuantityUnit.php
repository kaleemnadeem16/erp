<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Support\ItemQuantityRules;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR 0050's item-specific pack/alternate-unit conversion: "1 uom_id = factor
 * × the item's own stock UoM," resolved before the global, dimension-locked
 * UomConversionService by ItemUomConversionService. There is deliberately no
 * base_uom_id column — factor is always relative to whatever items.stock_uom_id
 * currently is, and Item locks stock_uom_id once any stock_move exists. See
 * docs/domain/units-of-measure.md#item-specific-conversions.
 *
 * @property int $item_id
 * @property int $uom_id
 * @property string $factor
 * @property string|null $quantity_precision
 * @property-read Item $item
 * @property-read Uom $uom
 */
class ItemQuantityUnit extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (ItemQuantityUnit $itemQuantityUnit): void {
            if (! $itemQuantityUnit->exists || $itemQuantityUnit->isDirty(['factor', 'quantity_precision'])) {
                ItemQuantityRules::assertUnit($itemQuantityUnit);
            }
        });

        static::updating(function (ItemQuantityUnit $itemQuantityUnit): void {
            if ($itemQuantityUnit->isDirty(['item_id', 'uom_id'])) {
                throw new DomainInvariantException(
                    'An item-quantity-unit override cannot be repointed; delete it and create another.',
                    $itemQuantityUnit->isDirty('item_id') ? 'item_id' : 'uom_id',
                );
            }
        });
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'uom_id' => Uom::class];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['factor' => 'decimal:9', 'quantity_precision' => 'decimal:9'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}

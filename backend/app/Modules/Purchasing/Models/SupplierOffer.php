<?php

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $item_supplier_id
 * @property int|null $item_packaging_id
 * @property int $purchase_uom_id
 * @property string|null $supplier_item_code
 * @property bool $preferred
 * @property string|null $minimum_order_qty
 * @property string|null $order_multiple
 * @property int|null $lead_time_days
 * @property string $unit_price
 * @property int $currency_id
 * @property int|null $price_uom_id
 * @property string $price_quantity
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_to
 * @property bool $active
 */
class SupplierOffer extends Model
{
    use BelongsToCompany,GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4', 'price_quantity' => 'decimal:4', 'minimum_order_qty' => 'decimal:4', 'order_multiple' => 'decimal:4', 'active' => 'bool', 'preferred' => 'bool', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_supplier_id' => ItemSupplier::class, 'item_packaging_id' => ItemPackaging::class, 'purchase_uom_id' => Uom::class, 'price_uom_id' => Uom::class];
    }

    /** @return BelongsTo<ItemSupplier, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(ItemSupplier::class, 'item_supplier_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['item_supplier_id', 'item_packaging_id', 'purchase_uom_id'])) {
                throw new DomainInvariantException('Identity/content cannot be repointed; archive and create a replacement.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Archive instead of deleting.'));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Models\Model;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Parties\Models\SupplierAccount;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $item_id
 * @property int $supplier_account_id
 * @property string|null $supplier_item_code
 * @property int|null $priority
 * @property int|null $lead_time_days
 * @property string|null $minimum_order_qty
 * @property int|null $purchase_uom_id
 * @property bool $active
 * @property-read Item $item
 * @property-read SupplierAccount $supplierAccount
 * @property-read Uom|null $purchaseUom
 */
class ItemSupplier extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'item_id' => Item::class,
            'supplier_account_id' => SupplierAccount::class,
            'purchase_uom_id' => Uom::class,
        ];
    }

    protected function casts(): array
    {
        return ['active' => 'bool', 'minimum_order_qty' => 'decimal:4'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<SupplierAccount, $this> */
    public function supplierAccount(): BelongsTo
    {
        return $this->belongsTo(SupplierAccount::class);
    }

    /** @return BelongsTo<Uom, $this> */
    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    public function resolvedLeadTimeDays(): ?int
    {
        return $this->lead_time_days ?? $this->supplierAccount->lead_time_days;
    }
}

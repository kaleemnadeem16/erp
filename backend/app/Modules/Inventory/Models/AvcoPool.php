<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvcoPool extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'warehouse_id' => Warehouse::class, 'batch_id' => StockBatch::class];
    }

    protected function casts(): array
    {
        return [
            'scope' => AvcoPoolScope::class,
            'status' => AvcoPoolStatus::class,
            'qty_on_hand' => 'decimal:4',
            'inventory_value' => 'decimal:4',
            'avg_unit_cost' => 'decimal:4',
            'base_avg_unit_cost' => 'decimal:4',
            'last_valid_avg_unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}

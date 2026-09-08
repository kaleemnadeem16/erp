<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $item_id
 * @property AvcoPoolScope $scope
 * @property int|null $warehouse_id
 * @property int|null $batch_id
 * @property string $qty_on_hand
 * @property string $inventory_value
 * @property string $avg_unit_cost
 * @property string $base_avg_unit_cost
 * @property string $last_valid_avg_unit_cost
 * @property AvcoPoolStatus $status
 */
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

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }
}

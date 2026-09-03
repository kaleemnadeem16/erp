<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NegativeStockShortage extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'item_id' => Item::class,
            'location_id' => Location::class,
            'batch_id' => StockBatch::class,
            'avco_pool_id' => AvcoPool::class,
            'source_move_id' => StockMove::class,
            'override_request_id' => NegativeStockOverrideRequest::class,
        ];
    }

    protected function casts(): array
    {
        return ['qty_open' => 'decimal:4', 'provisional_unit_cost' => 'decimal:4', 'settled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<NegativeStockOverrideRequest, $this> */
    public function overrideRequest(): BelongsTo
    {
        return $this->belongsTo(NegativeStockOverrideRequest::class, 'override_request_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record that one StockMove consumed a specific quantity of one
 * StockLayer, at that layer's own cost — the fact LayerAllocator::allocate()
 * computes but PostInventoryMovement previously discarded once qty_remaining
 * was decremented. FIFO/SPECIFIC only (see LayerAllocator::recordConsumption()):
 * AVCO's own replay works from the value ledger and has no reader for this.
 */
class StockLayerConsumption extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Stock layer consumptions are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Stock layer consumptions cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['stock_move_id' => StockMove::class, 'stock_layer_id' => StockLayer::class];
    }

    protected function casts(): array
    {
        return ['qty_consumed' => 'decimal:4', 'unit_cost' => 'decimal:4'];
    }

    /** @return BelongsTo<StockMove, $this> */
    public function stockMove(): BelongsTo
    {
        return $this->belongsTo(StockMove::class);
    }

    /** @return BelongsTo<StockLayer, $this> */
    public function stockLayer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class);
    }
}

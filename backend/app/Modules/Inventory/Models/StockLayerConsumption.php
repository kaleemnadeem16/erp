<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable allocation ledger. ALLOCATION and receipt REVERSAL rows consume a
 * layer; ALLOCATION_REVERSAL points to and negates part or all of one prior
 * ALLOCATION without changing it. FIFO/SPECIFIC only.
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
        return [
            'stock_move_id' => StockMove::class,
            'stock_layer_id' => StockLayer::class,
            'reverses_consumption_id' => self::class,
            'recalculation_request_id' => RecalculationRequest::class,
        ];
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

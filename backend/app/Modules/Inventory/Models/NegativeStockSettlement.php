<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NegativeStockSettlement extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Negative-stock settlements are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Negative-stock settlements cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['shortage_id' => NegativeStockShortage::class, 'inbound_move_id' => StockMove::class];
    }

    /** @return BelongsTo<StockMove, $this> */
    public function inboundMove(): BelongsTo
    {
        return $this->belongsTo(StockMove::class, 'inbound_move_id');
    }

    protected function casts(): array
    {
        return [
            'qty_settled' => 'decimal:4', 'provisional_unit_cost' => 'decimal:4',
            'actual_unit_cost' => 'decimal:4', 'unit_cost_difference' => 'decimal:4',
            'valuation_adjustment' => 'decimal:4', 'settled_at' => 'immutable_datetime',
        ];
    }
}

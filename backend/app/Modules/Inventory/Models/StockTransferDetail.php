<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable record of which AVCO pools a transfer's value moved between, kept
 * for valuation and audit. Per ADR 0045, a transfer's source rate is final at
 * post time — there is no provisional state and nothing here is ever repaired.
 */
class StockTransferDetail extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Stock transfer details are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Stock transfer details cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['stock_move_id' => StockMove::class, 'source_avco_pool_id' => AvcoPool::class, 'destination_avco_pool_id' => AvcoPool::class];
    }

    /** @return BelongsTo<StockMove, $this> */
    public function stockMove(): BelongsTo
    {
        return $this->belongsTo(StockMove::class);
    }
}

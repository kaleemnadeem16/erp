<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    protected function casts(): array
    {
        return ['is_provisional' => 'boolean'];
    }

    /** @return BelongsTo<StockMove, $this> */
    public function stockMove(): BelongsTo
    {
        return $this->belongsTo(StockMove::class);
    }

    /** @return HasOne<StockTransferRepair, $this> */
    public function repair(): HasOne
    {
        return $this->hasOne(StockTransferRepair::class);
    }
}

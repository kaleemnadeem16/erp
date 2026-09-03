<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\Uom;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StockMove extends Model
{
    use BelongsToCompany;
    use GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Posted stock movements are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Posted stock movements cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'from_location_id' => Location::class, 'to_location_id' => Location::class, 'uom_id' => Uom::class, 'batch_id' => StockBatch::class];
    }

    protected function casts(): array
    {
        return ['qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'fx_rate' => 'decimal:9', 'moved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasOne<StockValuationEntry, $this> */
    public function valuationEntry(): HasOne
    {
        return $this->hasOne(StockValuationEntry::class);
    }

    /** @return HasMany<StockValuationEntry, $this> */
    public function valuationEntries(): HasMany
    {
        return $this->hasMany(StockValuationEntry::class);
    }

    /** @return HasOne<NegativeStockShortage, $this> */
    public function negativeStockShortage(): HasOne
    {
        return $this->hasOne(NegativeStockShortage::class, 'source_move_id');
    }

    /** @return HasOne<StockTransferDetail, $this> */
    public function transferDetail(): HasOne
    {
        return $this->hasOne(StockTransferDetail::class, 'stock_move_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Purchasing\Support\DispositionOutcome;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Support\Concerns\BelongsToCompanyThroughParent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int|null $purchase_order_line_id
 * @property int $item_id
 * @property int $location_id
 * @property string $stock_qty
 * @property int $stock_uom_id
 * @property string $provisional_unit_cost
 * @property int $currency_id
 * @property string $fx_rate
 * @property string $base_unit_cost
 * @property Carbon $fx_rate_date
 * @property string|null $batch_no
 * @property string|null $serial_no
 * @property-read GoodsReceipt $goodsReceipt
 */
class GoodsReceiptLine extends Model
{
    use BelongsToCompanyThroughParent;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (GoodsReceiptLine $line): void {
            if ($line->exists && $line->goodsReceipt->status !== GoodsReceiptStatus::Draft) {
                throw new DomainInvariantException('Posted goods-receipt lines are immutable.', 'status');
            }

            foreach (['purchase_order_line_id' => PurchaseOrderLine::class, 'item_id' => Item::class, 'location_id' => Location::class] as $field => $model) {
                if ($line->{$field} !== null && ! $model::query()->whereKey($line->{$field})->exists()) {
                    throw new DomainInvariantException("{$field} does not resolve in the current company.", $field);
                }
            }
        });
    }

    protected static function companyScopeRelation(): string
    {
        return 'goodsReceipt';
    }

    protected function casts(): array
    {
        return [
            'received_qty' => 'decimal:4', 'stock_qty' => 'decimal:4', 'uom_factor' => 'decimal:9',
            'provisional_unit_cost' => 'decimal:4', 'fx_rate' => 'decimal:9', 'base_unit_cost' => 'decimal:4',
            'fx_rate_date' => 'date',
        ];
    }

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<QualityDisposition, $this> */
    public function qualityDispositions(): HasMany
    {
        return $this->hasMany(QualityDisposition::class);
    }

    /**
     * Eager-loads everything GoodsReceiptLineResource's qa_unallocated_qty/
     * qa_unresolved_qty fields need for a whole collection in one pair of queries,
     * instead of each rendered row running its own aggregate (and its own
     * purchaseOrderLine lookup) independently.
     *
     * @param  Builder<GoodsReceiptLine>  $query
     */
    public function scopeWithQaAggregates(Builder $query): void
    {
        $query->with('purchaseOrderLine')
            ->withSum(['qualityDispositions as qa_root_allocated_qty' => fn (Builder $q) => $q->whereNull('source_disposition_id')], 'qty')
            ->withSum(['qualityDispositions as qa_terminal_allocated_qty' => fn (Builder $q) => $q->whereIn('disposition', DispositionOutcome::terminalValues())], 'qty');
    }
}

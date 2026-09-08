<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\InventoryCutoverNegativeOpeningStatus;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * ADR 0037's cutover-shortage exception: an evidenced AVCO/FIFO negative
 * opening balance the predecessor system genuinely closed at, preserved
 * instead of falsified to zero. Requested and approved by different actors,
 * mirroring ApproveInventoryCutover's own independence rule. Once approved,
 * CommitInventoryCutoverOpeningState posts the matching NegativeStockShortage
 * (status OPEN_CUTOVER_SHORTAGE) — settled afterwards by ordinary compatible
 * inbound stock exactly like a live shortage (ShortageSettler), never by an
 * invented event here.
 *
 * @property ValuationMethod $source_valuation_method
 * @property string $source_quantity
 * @property string $source_value
 * @property string $base_source_value
 * @property string $provisional_unit_cost
 * @property string $reason
 * @property array<string, mixed> $supporting_evidence
 * @property int $responsible_owner_id
 * @property string $resolution_plan
 * @property Carbon $target_date
 * @property string|null $target_date_reason
 * @property InventoryCutoverNegativeOpeningStatus $status
 * @property int $requested_by
 * @property Carbon|null $requested_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 */
class InventoryCutoverNegativeOpening extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (InventoryCutoverNegativeOpening $exception): void {
            $allowed = ['status', 'approved_by', 'approved_at', 'updated_at'];
            if (array_diff(array_keys($exception->getDirty()), $allowed) !== []) {
                throw new DomainInvariantException('Inventory cutover negative-opening evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new DomainInvariantException('Inventory cutover negative-opening exceptions cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return [
            'inventory_cutover_id' => InventoryCutover::class, 'item_id' => Item::class,
            'location_id' => Location::class, 'batch_id' => StockBatch::class,
            'responsible_owner_id' => User::class, 'requested_by' => User::class, 'approved_by' => User::class,
        ];
    }

    protected function casts(): array
    {
        return [
            'source_valuation_method' => ValuationMethod::class, 'status' => InventoryCutoverNegativeOpeningStatus::class,
            'source_quantity' => 'decimal:4', 'source_value' => 'decimal:4', 'base_source_value' => 'decimal:4',
            'provisional_unit_cost' => 'decimal:4', 'supporting_evidence' => 'array',
            'target_date' => 'immutable_date', 'requested_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<InventoryCutover, $this> */
    public function cutover(): BelongsTo
    {
        return $this->belongsTo(InventoryCutover::class, 'inventory_cutover_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return HasOne<NegativeStockShortage, $this> */
    public function shortage(): HasOne
    {
        return $this->hasOne(NegativeStockShortage::class, 'inventory_cutover_negative_opening_id');
    }
}

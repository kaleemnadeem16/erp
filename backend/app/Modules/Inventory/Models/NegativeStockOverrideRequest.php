<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\NegativeStockAuthorisationMode;
use App\Modules\Inventory\Support\NegativeStockMovementClass;
use App\Modules\Inventory\Support\NegativeStockReasonCode;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $requested_by
 * @property int $item_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property NegativeStockMovementClass $movement_class
 * @property NegativeStockReasonCode $reason_code
 * @property string $requested_qty
 * @property string $available_qty
 * @property string $explanation
 * @property string $source_type
 * @property int $source_id
 * @property NegativeStockAuthorisationMode $authorisation_mode
 * @property bool $allow_self_review
 * @property string $shortage_qty
 * @property Carbon $requested_at
 * @property Carbon|null $review_due_at
 */
class NegativeStockOverrideRequest extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Negative-stock override requests are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Negative-stock override requests cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'location_id' => Location::class, 'batch_id' => StockBatch::class, 'requested_by' => User::class];
    }

    protected function casts(): array
    {
        return ['movement_class' => NegativeStockMovementClass::class, 'reason_code' => NegativeStockReasonCode::class, 'authorisation_mode' => NegativeStockAuthorisationMode::class, 'allow_self_review' => 'boolean', 'requested_qty' => 'decimal:4', 'available_qty' => 'decimal:4', 'shortage_qty' => 'decimal:4', 'requested_at' => 'immutable_datetime', 'review_due_at' => 'immutable_datetime'];
    }

    /** @return HasOne<NegativeStockOverrideApproval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(NegativeStockOverrideApproval::class, 'request_id');
    }

    /** @return HasOne<NegativeStockOverrideReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(NegativeStockOverrideReview::class, 'request_id');
    }

    /** @return HasOne<NegativeStockShortage, $this> */
    public function shortage(): HasOne
    {
        return $this->hasOne(NegativeStockShortage::class, 'override_request_id');
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

    /** @return BelongsTo<StockBatch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }
}

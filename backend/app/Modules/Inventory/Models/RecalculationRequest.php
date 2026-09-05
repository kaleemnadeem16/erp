<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Models\User;
use App\Modules\Inventory\Support\BackdatingHorizonMode;
use App\Modules\Inventory\Support\RecalculationMovementKind;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One continuing ADR 0040 recalculation case for one backdated receipt, issue or transfer —
 * see docs/domain/stock-recalculation.md. Immutable: everything that can
 * change over the request's life (impact versions, approvals) lives in child
 * records, never here.
 *
 * @property int $item_id
 * @property int $location_id
 * @property int|null $batch_id
 * @property string|null $serial_no
 * @property string $qty
 * @property RecalculationMovementKind $movement_kind
 * @property int|null $destination_location_id
 * @property string|null $unit_cost
 * @property int|null $currency_id
 * @property string|null $fx_rate
 * @property string|null $base_unit_cost
 * @property Carbon $moved_at
 * @property string $source_type
 * @property int $source_id
 * @property BackdatingHorizonMode $horizon_mode
 * @property Carbon|null $horizon_permitted_floor
 * @property int $requested_by
 * @property Carbon $requested_at
 * @property Carbon $review_due_at
 */
class RecalculationRequest extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainInvariantException('Recalculation requests are immutable.'));
        static::deleting(fn () => throw new DomainInvariantException('Recalculation requests cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['item_id' => Item::class, 'location_id' => Location::class, 'batch_id' => StockBatch::class, 'destination_location_id' => Location::class, 'requested_by' => User::class];
    }

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4', 'unit_cost' => 'decimal:4', 'fx_rate' => 'decimal:9', 'base_unit_cost' => 'decimal:4',
            'movement_kind' => RecalculationMovementKind::class,
            'moved_at' => 'immutable_datetime', 'horizon_mode' => BackdatingHorizonMode::class,
            'horizon_permitted_floor' => 'immutable_date', 'requested_at' => 'immutable_datetime', 'review_due_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<RecalculationImpactVersion, $this> */
    public function impactVersions(): HasMany
    {
        return $this->hasMany(RecalculationImpactVersion::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** The latest, still-current (non-stale) impact version. */
    public function currentImpactVersion(): ?RecalculationImpactVersion
    {
        return $this->impactVersions()->where('is_stale', false)->orderByDesc('version_no')->first();
    }

    public function isOverdue(): bool
    {
        return $this->review_due_at->isPast() && $this->currentImpactVersion()?->isDecided() !== true;
    }
}

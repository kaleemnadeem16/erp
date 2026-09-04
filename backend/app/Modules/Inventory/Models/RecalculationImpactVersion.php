<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One immutable, append-only computed impact for a RecalculationRequest — see
 * docs/domain/stock-recalculation.md. Every column except is_stale is frozen
 * at creation (enforced by a narrower DB trigger than the blanket
 * immutability used elsewhere — see the creating migration); is_stale may
 * only flip false to true, when DecideRecalculationApproval finds this
 * version's numbers no longer match a fresh replay and appends a
 * replacement under the same request.
 *
 * @property int $recalculation_request_id
 * @property int $version_no
 * @property int $avco_pool_id
 * @property string $original_qty_on_hand
 * @property string $original_inventory_value
 * @property string $original_avg_unit_cost
 * @property string $counterfactual_qty_on_hand
 * @property string $counterfactual_inventory_value
 * @property string $counterfactual_avg_unit_cost
 * @property string $valuation_adjustment
 * @property Carbon $computed_at
 * @property bool $is_stale
 */
class RecalculationImpactVersion extends Model
{
    use BelongsToCompany, GuardsRelatedCompanyScope;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainInvariantException('Recalculation impact versions cannot be deleted.'));
    }

    protected static function companyScopedForeignKeys(): array
    {
        return ['recalculation_request_id' => RecalculationRequest::class, 'avco_pool_id' => AvcoPool::class];
    }

    protected function casts(): array
    {
        return [
            'original_qty_on_hand' => 'decimal:4', 'original_inventory_value' => 'decimal:4', 'original_avg_unit_cost' => 'decimal:4',
            'counterfactual_qty_on_hand' => 'decimal:4', 'counterfactual_inventory_value' => 'decimal:4', 'counterfactual_avg_unit_cost' => 'decimal:4',
            'valuation_adjustment' => 'decimal:4', 'computed_at' => 'immutable_datetime', 'is_stale' => 'boolean',
        ];
    }

    /** @return BelongsTo<RecalculationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RecalculationRequest::class, 'recalculation_request_id');
    }

    /** @return HasOne<RecalculationApproval, $this> */
    public function approval(): HasOne
    {
        return $this->hasOne(RecalculationApproval::class, 'impact_version_id');
    }
}

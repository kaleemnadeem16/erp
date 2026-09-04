<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Exceptions\DomainInvariantException;
use App\Models\Model;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Modules\Inventory\Support\RecalculationRiskClassification;
use App\Support\Concerns\BelongsToCompany;
use App\Support\Concerns\GuardsRelatedCompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * risk_classification is set once, by RecalculationRiskClassifier, at the
 * same time as every other economic column, and determines how many
 * approvals() rows this version requires: one for NORMAL_RISK, two
 * independent approvers for HIGH_RISK (see DecideRecalculationApproval and
 * the guard_recalculation_approval_insert DB trigger, which enforces the
 * same count).
 *
 * @property int $recalculation_request_id
 * @property int $version_no
 * @property int|null $avco_pool_id
 * @property string $original_qty_on_hand
 * @property string $original_inventory_value
 * @property string $original_avg_unit_cost
 * @property string $counterfactual_qty_on_hand
 * @property string $counterfactual_inventory_value
 * @property string $counterfactual_avg_unit_cost
 * @property string $valuation_adjustment
 * @property Carbon $computed_at
 * @property bool $is_stale
 * @property RecalculationRiskClassification $risk_classification
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
            'risk_classification' => RecalculationRiskClassification::class,
        ];
    }

    /** @return BelongsTo<RecalculationRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(RecalculationRequest::class, 'recalculation_request_id');
    }

    /** @return HasMany<RecalculationApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(RecalculationApproval::class, 'impact_version_id');
    }

    public function requiredApprovalCount(): int
    {
        return $this->risk_classification->requiredApprovalCount();
    }

    public function isRejected(): bool
    {
        return $this->approvals->contains(fn (RecalculationApproval $approval): bool => $approval->decision === RecalculationApprovalDecision::Rejected);
    }

    public function isFullyApproved(): bool
    {
        $approvedCount = $this->approvals->filter(fn (RecalculationApproval $approval): bool => $approval->decision === RecalculationApprovalDecision::Approved)->count();

        return $approvedCount >= $this->requiredApprovalCount();
    }

    /** Terminal: either fully approved or rejected — no further decisions are accepted. */
    public function isDecided(): bool
    {
        return $this->isRejected() || $this->isFullyApproved();
    }

    /** The one approval whose acceptance completed the requirement — the only row carrying expires_at. */
    public function completingApproval(): ?RecalculationApproval
    {
        return $this->approvals->first(
            fn (RecalculationApproval $approval): bool => $approval->decision === RecalculationApprovalDecision::Approved && $approval->expires_at !== null,
        );
    }
}

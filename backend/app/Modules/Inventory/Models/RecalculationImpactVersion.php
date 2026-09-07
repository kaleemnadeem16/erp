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
use Illuminate\Support\Collection;

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
 * distinct approvers() this version requires: one for NORMAL_RISK, two
 * independent approvers for HIGH_RISK (see DecideRecalculationApproval and
 * the guard_recalculation_approval_insert DB trigger, which enforces the
 * same count). approvals() may hold more rows than that once an approver's
 * expired decision has been renewed — required count is measured in
 * distinct decided_by, not row count; see approvedActorIds().
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
            'risk_classification' => RecalculationRiskClassification::class, 'movement_impact' => 'array',
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

    /**
     * Each distinct approver's most recent APPROVED decision — a renewal
     * appends a fresh row rather than reviving the old one, so this is the
     * row that speaks for that approver's slot now. Rejection is excluded:
     * it's terminal and handled separately by isRejected().
     *
     * @return Collection<int, RecalculationApproval>
     */
    public function currentApprovalsByActor(): Collection
    {
        return $this->approvals
            ->filter(fn (RecalculationApproval $approval): bool => $approval->decision === RecalculationApprovalDecision::Approved)
            ->groupBy('decided_by')
            ->map(fn (Collection $decisions): RecalculationApproval => $decisions->sortByDesc('id')->first());
    }

    /**
     * Distinct approvers who have ever held an APPROVED decision, regardless
     * of expiry — the historical/structural fact isFullyApproved() reads,
     * and how a renewal recognises "this is the same actor reclaiming their
     * own slot" regardless of whether it (or anyone else's) has since
     * expired. Whether an expired slot is currently open for a *different*
     * approver to claim is a separate question — see
     * unexpiredApprovedActorIds().
     *
     * @return Collection<int, int>
     */
    public function approvedActorIds(): Collection
    {
        return $this->currentApprovalsByActor()->keys();
    }

    public function isFullyApproved(): bool
    {
        return $this->approvedActorIds()->count() >= $this->requiredApprovalCount();
    }

    /**
     * Distinct approvers whose most recent APPROVED decision is still live
     * right now — an expired one drops out, freeing that slot for a
     * different eligible approver to claim. This is ADR 0040's
     * authority-only rerouting (`AUTHORITY_ONLY_CHANGE:
     * REROUTE_OR_INVALIDATE_APPROVAL_WITHOUT_NEW_IMPACT_VERSION`): the
     * economics haven't changed, so no new impact version is needed, only a
     * different actor filling the vacated slot. Consulted only for admitting
     * a genuinely *different* actor — the same actor renewing their own
     * expired slot is detected via approvedActorIds() instead, since a
     * renewal reclaims their existing slot regardless of whether it (or
     * anyone else's) has expired.
     *
     * @return Collection<int, int>
     */
    public function unexpiredApprovedActorIds(): Collection
    {
        return $this->currentApprovalsByActor()
            ->filter(fn (RecalculationApproval $approval): bool => ! $approval->isExpired())
            ->keys();
    }

    /**
     * ADR 0040 "Approved execution lifetime": ALL_REQUIRED_APPROVALS_PRESENT
     * and ALL_REQUIRED_APPROVALS_UNEXPIRED. Unlike isFullyApproved() (a
     * historical/structural fact that survives expiry), this asks whether
     * execution could proceed *right now* — at least as many distinct
     * approvers as required currently hold a live decision. Authority-only
     * rerouting can leave more distinct historical approvers than a version
     * actually requires (an expired original plus whoever rerouted into
     * their slot), so this counts live slots rather than demanding every
     * approver who ever decided still be unexpired.
     */
    public function hasUnexpiredFullApproval(): bool
    {
        return $this->unexpiredApprovedActorIds()->count() >= $this->requiredApprovalCount();
    }

    /** Terminal: either fully approved or rejected — no further decisions are accepted. */
    public function isDecided(): bool
    {
        return $this->isRejected() || $this->isFullyApproved();
    }
}

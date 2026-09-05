<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Actions\Recalculation\RecalculationImpactBuilder;
use App\Modules\Inventory\Actions\Recalculation\RecalculationRiskClassifier;
use App\Modules\Inventory\Actions\Recalculation\RegenerationLimit;
use App\Modules\Inventory\Models\RecalculationApproval;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\RecalculationApprovalDecision;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Decides one exact RecalculationImpactVersion — never the parent request
 * generally, per ADR 0040. Requester/approver separation is absolute (no
 * small-company self-review exception: that's Q13's negative-stock immediate
 * review only, not this Q14 approval). Revalidates by re-running the replay
 * as a precondition of deciding: if the ledger changed since this version was
 * computed, the version is marked stale. A replacement requires fresh review;
 * at the regeneration cap, replacement instead waits for manual coordination.
 *
 * A version's own risk_classification (set once, at creation, by
 * RecalculationRiskClassifier) determines how many independent approvers it
 * needs: one for NORMAL_RISK, two for HIGH_RISK. Each eligible actor may
 * decide a version at most once (enforced by a unique DB constraint on
 * (impact_version_id, decided_by), on top of the requester exclusion above —
 * "independent" means a different person than the requester *and* than any
 * approver who already decided this version). A REJECTED decision is
 * terminal: no further decisions are accepted from anyone once one lands,
 * whether it was the first or the second. Only the approval that brings the
 * version's approved count up to its required number carries expires_at —
 * approved-execution authority is a property of the version being fully
 * approved, not of any single decision, so a HIGH_RISK version's first
 * approval alone grants no execution window.
 */
final class DecideRecalculationApproval
{
    public function __construct(
        private readonly RecalculationImpactBuilder $impacts,
        private readonly RegenerationLimit $limit,
    ) {}

    public function __invoke(RecalculationImpactVersion $version, RecalculationApprovalDecision $decision, string $notes, User $actor): RecalculationApproval
    {
        if (trim($notes) === '') {
            throw new DomainInvariantException('An approval decision requires notes.', 'notes');
        }

        // Revalidation may need to commit a stale-flip + replacement version
        // even on the path that ultimately still refuses the decision — a
        // thrown exception here would roll the transaction back and silently
        // discard the replacement, defeating the whole point of generating
        // it. So the closure returns null as a "regenerated" sentinel instead
        // of throwing, and the actual exception is raised below, once that
        // commit has already landed.
        $outcome = DB::transaction(function () use ($version, $decision, $notes, $actor): RecalculationApproval|null {
            $company = Company::query()->lockForUpdate()->findOrFail(app(CompanyContext::class)->id());
            $request = RecalculationRequest::query()->lockForUpdate()->findOrFail($version->recalculation_request_id);
            $version = RecalculationImpactVersion::query()->lockForUpdate()->with('approvals')->findOrFail($version->id);
            if ($version->is_stale) {
                throw new DomainInvariantException("This impact version is stale — review the request's current version instead.", 'impact_version_id');
            }
            if ($request->requested_by === $actor->id) {
                throw new DomainInvariantException('A recalculation request must be decided by a different user.', 'decided_by');
            }
            if (! $actor->can('inventory.recalculation.approve')) {
                throw new DomainInvariantException('The actor is not permitted to decide recalculation requests.', 'decided_by');
            }

            $existingByActor = $version->approvals->firstWhere('decided_by', $actor->id);
            if ($existingByActor !== null) {
                if ($existingByActor->decision !== $decision || $existingByActor->notes !== trim($notes)) {
                    throw new DomainInvariantException('This actor already decided this impact version differently.', 'impact_version_id');
                }

                return $existingByActor;
            }
            if ($version->isRejected()) {
                throw new DomainInvariantException('This impact version has already been rejected — no further decisions are accepted.', 'impact_version_id');
            }
            if ($version->isFullyApproved()) {
                throw new DomainInvariantException('This impact version already has its required number of approvals.', 'impact_version_id');
            }

            $attributes = $this->impacts->attributes($request, $company);
            if (! $this->impacts->matches($version, $attributes)) {
                $version->update(['is_stale' => true]);
                if ($this->limit->pending($request) !== null) {
                    return null;
                }
                $this->impacts->append($request, $attributes);

                return null;
            }

            $approvedCountBefore = $version->approvals->filter(fn (RecalculationApproval $a): bool => $a->decision === RecalculationApprovalDecision::Approved)->count();
            if ($version->requiredApprovalCount() === 2 && $approvedCountBefore >= 1
                && ! $actor->can('inventory.recalculation.approve-high-risk')) {
                throw new DomainInvariantException('The second high-risk decision requires dedicated higher-risk authority.', 'decided_by');
            }
            $willComplete = $decision === RecalculationApprovalDecision::Approved && ($approvedCountBefore + 1) >= $version->requiredApprovalCount();

            return RecalculationApproval::query()->create([
                'impact_version_id' => $version->id, 'decision' => $decision, 'notes' => trim($notes),
                'decided_by' => $actor->id, 'decided_at' => now(),
                'expires_at' => $willComplete ? now()->addHours($company->recalculation_approval_ttl_hours) : null,
            ]);
        });

        if ($outcome === null) {
            $request = RecalculationRequest::query()->findOrFail($version->recalculation_request_id);
            $pause = $this->limit->pending($request);
            throw new DomainInvariantException($pause === null
                ? 'The impact or its risk/dependencies changed — a new version requires fresh review.'
                : 'Automatic regeneration is paused for '.$pause['escalation'].'. Manual coordination is required.', 'impact_version_id');
        }

        return $outcome;
    }
}

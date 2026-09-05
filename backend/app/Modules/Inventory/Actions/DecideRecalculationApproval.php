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
 * needs: one for NORMAL_RISK, two for HIGH_RISK — measured in distinct
 * decided_by among APPROVED decisions (RecalculationImpactVersion::
 * approvedActorIds()), on top of the requester exclusion above.
 * "Independent" means a different person than the requester *and* than any
 * *other* approver already holding a slot. A REJECTED decision is terminal:
 * no further decisions are accepted from anyone once one lands, whether it
 * was the first or the second.
 *
 * Per ADR 0040's APPROVAL_TTL_TARGET: EACH_REQUIRED_APPROVAL, every APPROVED
 * decision carries its own expires_at the moment it's granted — a HIGH_RISK
 * version's first approval gets a real execution-window deadline too, not
 * just its completing second. Approvals stay immutable and append-only, so
 * once an approver's own decision has expired, they may decide again: that
 * append is a *renewal* of their existing slot, not a new approver claiming
 * it, and never counts against the version's required-approver cap (an
 * unexpired existing decision from the same actor is rejected instead, by
 * both this Action and the guard_recalculation_approval_insert DB trigger —
 * one live decision per approver per version at a time). A renewal also
 * skips the second-approver high-risk-authority check below, since that
 * authority was already validated when the actor's slot was first granted;
 * a *different* person taking over an expired slot is authority-only
 * rerouting, a separate ADR 0040 mechanism this Action does not implement.
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

            $existingByActor = $version->approvals->where('decided_by', $actor->id)->sortByDesc('id')->first();
            if ($existingByActor !== null && ! $existingByActor->isExpired()) {
                if ($existingByActor->decision !== $decision || $existingByActor->notes !== trim($notes)) {
                    throw new DomainInvariantException('This actor already decided this impact version differently.', 'impact_version_id');
                }

                return $existingByActor;
            }
            if ($version->isRejected()) {
                throw new DomainInvariantException('This impact version has already been rejected — no further decisions are accepted.', 'impact_version_id');
            }
            $approvedActorIds = $version->approvedActorIds();
            $isRenewal = $approvedActorIds->contains($actor->id);
            if (! $isRenewal && $approvedActorIds->count() >= $version->requiredApprovalCount()) {
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

            if (! $isRenewal && $version->requiredApprovalCount() === 2 && $approvedActorIds->isNotEmpty()
                && ! $actor->can('inventory.recalculation.approve-high-risk')) {
                throw new DomainInvariantException('The second high-risk decision requires dedicated higher-risk authority.', 'decided_by');
            }

            return RecalculationApproval::query()->create([
                'impact_version_id' => $version->id, 'decision' => $decision, 'notes' => trim($notes),
                'decided_by' => $actor->id, 'decided_at' => now(),
                'expires_at' => $decision === RecalculationApprovalDecision::Approved ? now()->addHours($company->recalculation_approval_ttl_hours) : null,
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

<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use App\Modules\Approvals\Support\ApprovalDecisionKind;
use App\Modules\Approvals\Support\ApprovalEligibility;
use App\Modules\Approvals\Support\ApprovalGrant;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalStepStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Approvals\Support\ExpiryAction;
use Illuminate\Support\Facades\DB;

/**
 * Records one decision on an open step (ADR 0070). In order it checks that the document is unchanged since it was
 * routed, that the step has not expired, that the actor holds the authority — their own or a delegator's — that the
 * submitter is not satisfying a step that forbids it, and that this person has not already filled a slot in the step
 * (as themselves or as a delegator's principal). Only then does it append the decision and advance the request.
 *
 * Rejection is terminal for the request: the submitter revises and submits again, as a new request. Approving the
 * last step of the last stage approves the request; it never issues or posts the document itself.
 */
final class DecideApproval
{
    public function __construct(
        private readonly ApprovalSubjectRegistry $subjects,
        private readonly ApprovalEligibility $eligibility,
        private readonly OpenApprovalStage $openStage,
        private readonly InvalidateApprovals $invalidate,
    ) {}

    public function __invoke(ApprovalRequest $request, User $actor, ApprovalDecisionKind $decision, ?string $reason = null, ?int $stepId = null): ApprovalRequest
    {
        if ($decision === ApprovalDecisionKind::Rejected && trim((string) $reason) === '') {
            throw new DomainInvariantException('A rejection needs a reason.', 'reason');
        }

        // Cancelling a stale or expired approval must survive the refusal that follows it, so those checks run (and
        // commit) before the transaction that records a decision — a throw inside it would roll the cancellation back.
        $this->preflight(ApprovalRequest::query()->findOrFail($request->id));

        return DB::transaction(function () use ($request, $actor, $decision, $reason, $stepId): ApprovalRequest {
            $request = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $request->isOpen()) {
                throw new DomainInvariantException("This approval is already {$request->status->value}.", 'status');
            }

            [$step, $grant] = $this->stepFor($request, $actor, $stepId);
            $policyStep = $step->policyStep;
            if (! $policyStep->allow_submitter && in_array($request->submitted_by, [$actor->id, $grant->principal->id], true)) {
                throw new DomainInvariantException('The person who submitted this cannot approve this step.', 'decided_by');
            }
            $taken = $step->decisions()->get();
            if ($taken->contains(fn (ApprovalDecision $d): bool => $d->decided_by === $actor->id || $d->principal_id === $actor->id
                || $d->principal_id === $grant->principal->id || $d->decided_by === $grant->principal->id)) {
                throw new DomainInvariantException('You have already filled an approval slot in this step, personally or on behalf of someone else.', 'decided_by');
            }

            ApprovalDecision::query()->create([
                'request_id' => $request->id, 'request_step_id' => $step->id, 'decision' => $decision,
                'decided_by' => $actor->id, 'principal_id' => $grant->principal->id, 'delegation_id' => $grant->delegation?->id,
                'reason' => $reason === null ? null : trim($reason), 'authority_snapshot' => $grant->snapshot, 'decided_at' => now(),
            ]);

            return $this->advance($request, $step, $decision, $reason);
        });
    }

    private function preflight(ApprovalRequest $request): void
    {
        if (! $request->isOpen()) {
            throw new DomainInvariantException("This approval is already {$request->status->value}.", 'status');
        }
        $this->assertUnchanged($request);
        $this->settleExpiry($request);
    }

    private function assertUnchanged(ApprovalRequest $request): void
    {
        $current = $this->subjects->get($request->subject_type)->routing($request->subject_id);
        if ($current->hash() !== $request->facts_hash) {
            ($this->invalidate)($request, 'The document changed after it was submitted for approval; it must be submitted again.');
            throw new DomainInvariantException('The document changed after it was submitted, so this approval was cancelled. It must be submitted again.', 'approval');
        }
    }

    /** A step past its deadline either becomes overdue (still decidable) or ends the request; it never decides. */
    private function settleExpiry(ApprovalRequest $request): void
    {
        foreach ($request->steps()->where('status', ApprovalStepStatus::Open)->whereNotNull('deadline_at')->where('deadline_at', '<', now())->with('policyStep')->get() as $step) {
            if ($step->policyStep->on_expiry === ExpiryAction::ExpireRequest) {
                ($this->invalidate)($request, 'The approval expired before it was decided.', ApprovalRequestStatus::Expired);
                throw new DomainInvariantException('This approval expired before it was decided and must be submitted again.', 'approval');
            }
            if ($step->overdue_at === null) {
                $step->update(['overdue_at' => now()]);
            }
        }
    }

    /** @return array{0: ApprovalRequestStep, 1: ApprovalGrant} */
    private function stepFor(ApprovalRequest $request, User $actor, ?int $stepId): array
    {
        $open = $request->steps()->where('status', ApprovalStepStatus::Open)->with('policyStep')->get()
            ->when($stepId !== null, fn ($steps) => $steps->where('id', $stepId));
        foreach ($open as $step) {
            $grant = $this->eligibility->grantFor($actor, $request, $step);
            if ($grant !== null) {
                return [$step, $grant];
            }
        }

        throw new DomainInvariantException('You are not eligible to decide any open step of this approval.', 'decided_by');
    }

    private function advance(ApprovalRequest $request, ApprovalRequestStep $step, ApprovalDecisionKind $decision, ?string $reason): ApprovalRequest
    {
        if ($decision === ApprovalDecisionKind::Rejected) {
            $step->update(['status' => ApprovalStepStatus::Rejected, 'completed_at' => now()]);
            $request->steps()->whereIn('status', [ApprovalStepStatus::Open, ApprovalStepStatus::Waiting])->update(['status' => ApprovalStepStatus::Closed]);
            $request->update(['status' => ApprovalRequestStatus::Rejected, 'closed_at' => now(), 'closing_reason' => trim((string) $reason)]);

            return $request->refresh();
        }

        $approved = $step->decisions()->where('decision', ApprovalDecisionKind::Approved)->count();
        if ($approved < $step->required_approvals) {
            return $request->refresh();
        }
        $step->update(['status' => ApprovalStepStatus::Approved, 'completed_at' => now()]);

        $stillOpen = $request->steps()->where('stage', $step->stage)->where('status', '!=', ApprovalStepStatus::Approved)->exists();
        if ($stillOpen) {
            return $request->refresh();
        }
        $next = $request->steps()->where('stage', '>', $step->stage)->min('stage');
        if ($next !== null) {
            ($this->openStage)($request, (int) $next);

            return $request->refresh();
        }
        $request->update(['status' => ApprovalRequestStatus::Approved, 'closed_at' => now()]);

        return $request->refresh();
    }
}

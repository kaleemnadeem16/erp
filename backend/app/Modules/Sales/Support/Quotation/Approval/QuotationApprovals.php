<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Approval;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalEligibility;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Sales\Models\QuotationRevision;

/**
 * The approval standing of one draft revision, derived (never stored): the latest approval request for the revision, and whether it was given for the draft EXACTLY as it is
 * now. A request whose content hash no longer equals the draft's current routing hash counts for nothing - a pending one is not decidable (the decision refuses and invalidates
 * it), an approved one is not honoured, a rejected one no longer describes the draft. Read-only: it changes nothing, so the readiness Action can use it.
 */
final class QuotationApprovals
{
    public const NOT_REQUESTED = 'NOT_REQUESTED';

    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public function __construct(private readonly ApprovalSubjectRegistry $subjects) {}

    public function latest(int $revisionId): ?ApprovalRequest
    {
        return ApprovalRequest::query()->where('subject_type', QuotationApprovalSubject::CODE)->where('subject_id', $revisionId)->orderByDesc('id')->first();
    }

    public function isCurrent(QuotationRevision $revision, ApprovalRequest $request): bool
    {
        try {
            $subject = app(QuotationApprovalSubject::class);
            $subject->assertRequestCurrent($request);
            if ($subject->strictPolicySelection() && $request->status === ApprovalRequestStatus::Approved) {
                foreach ($request->steps()->with('decisions')->get() as $step) {
                    foreach ($step->decisions as $decision) {
                        $person = User::query()->find($decision->decided_by);
                        if ($person === null || app(ApprovalEligibility::class)->grantFor($person, $request, $step) === null) {
                            return false;
                        }
                    }
                }
            }
        } catch (DomainInvariantException) {
            return false;
        }

        return hash_equals($request->facts_hash, $this->subjects->get(QuotationApprovalSubject::CODE)->routing($revision->id)->hash());
    }

    /** @return array{state: string, request: ApprovalRequest|null, current: bool} */
    public function standing(QuotationRevision $revision): array
    {
        $request = $this->latest($revision->id);
        if ($request === null) {
            return ['state' => self::NOT_REQUESTED, 'request' => null, 'current' => false];
        }
        $current = $this->isCurrent($revision, $request);
        $state = match (true) {
            ! $current => self::NOT_REQUESTED,
            $request->status === ApprovalRequestStatus::Pending => self::PENDING,
            $request->status === ApprovalRequestStatus::Approved => self::APPROVED,
            $request->status === ApprovalRequestStatus::Rejected => self::REJECTED,
            default => self::NOT_REQUESTED,
        };

        return ['state' => $state, 'request' => $request, 'current' => $current];
    }
}

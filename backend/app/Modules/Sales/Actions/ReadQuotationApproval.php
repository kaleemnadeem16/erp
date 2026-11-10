<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalDecision;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\DiscountAuthority\AssessDiscountAuthority;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\QuotationAuthorityApproval;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovals;
use App\Modules\Sales\Support\Quotation\Approval\QuotationApprovalSubject;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Where a draft stands with its approval, for the people with a part in it (ADR 0158): a preparer (`sales.quotation.prepare`) or a decider (`sales.quotation.approve`), each with
 * the independent view and judged by the quotation-family record policy for THIS revision (404 when it is not visible). Read-only. It carries codes, names and the decision
 * reasons the people involved wrote - never an amount, a limit, an authority ceiling or a cost. `can` says what THIS caller may do now so a screen never offers a button the
 * Action would refuse; the Actions repeat every check.
 */
final class ReadQuotationApproval
{
    public function __construct(private readonly QuotationApprovals $approvals, private readonly QuotationApprovalSubject $subject) {}

    /** @return array<string, mixed> */
    public function __invoke(User $actor, int $revisionId): array
    {
        ActiveActor::require($actor, 'use quotations');

        return DB::transaction(function () use ($actor, $revisionId): array {
            app(CompanyAccessFence::class)->lockAll();
            app(AuthorityFence::class)->lock();
            $actor = ActiveActor::require($actor, 'read quotation approval standing');
            [$revision, $preparer, $decider] = $this->visible($actor, $revisionId);
            $needed = $this->recordedNeed($revision);
            if ($revision->isDraft() && app(QuotationAuthorityApproval::class)->enabled()) {
                $authority = app(AssessDiscountAuthority::class)->assess($revision);
                $needed = ! in_array($authority['result'], ['ROUTINE', 'NOT_APPLICABLE'], true);
            }
            $standing = $this->approvals->standing($revision);
            $request = $standing['request'];
            $open = $request !== null && $request->isOpen() && $standing['current'];

            return [
                'revision_id' => $revision->id, 'input_generation' => $revision->input_generation, 'is_draft' => $revision->isDraft(),
                'approval_needed' => $needed,
                'state' => $standing['state'],
                // A request given for earlier content (changed since) is shown as history, never as the draft's standing.
                'superseded' => $request !== null && ! $standing['current'],
                'request' => $request === null ? null : [
                    'id' => $request->id, 'status' => $request->status->value, 'requested_at' => $request->submitted_at->toIso8601String(),
                    'requested_by' => $this->person($request->submitted_by),
                    'expires_at' => $request->facts['evidence']['authority_expires_at'] ?? null, 'expired' => $this->subject->requestHasExpired($request),
                    'closed_at' => $request->closed_at?->toIso8601String(), 'closing_reason' => $request->status === ApprovalRequestStatus::Pending ? null : $request->closing_reason,
                    'concessions' => $request->facts['facts']['concessions'] ?? null,
                    'decisions' => ApprovalDecision::query()->where('request_id', $request->id)->orderBy('id')->get()->map(fn (ApprovalDecision $decision): array => [
                        'decision' => $decision->decision->value, 'reason' => $decision->reason, 'decided_at' => $decision->decided_at->toIso8601String(), 'decided_by' => $this->person($decision->decided_by),
                    ])->all(),
                ],
                'can' => [
                    'request' => $preparer && $revision->isDraft() && $needed && in_array($standing['state'], [QuotationApprovals::NOT_REQUESTED], true),
                    'withdraw' => $preparer && $request !== null && $request->isOpen() && $request->submitted_by === $actor->id,
                    'decide' => $decider && $open && $revision->isDraft() && $request->submitted_by !== $actor->id && $this->subject->mayDecide($actor, $request, false),
                ],
            ];
        });
    }

    /** @return array{0: QuotationRevision, 1: bool, 2: bool} the revision, and whether the caller sees it as a preparer and/or as a decider */
    private function visible(User $actor, int $revisionId): array
    {
        $access = app(QuotationAccess::class);
        $preparer = null;
        $decider = null;
        $failure = null;
        try {
            $preparer = $access->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_PREPARE, PermissionVocabulary::QUOTATION_VIEW]);
        } catch (AuthorizationException|DomainInvariantException $refusal) {
            $failure = $refusal;
        }
        try {
            $decider = $access->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_APPROVE, PermissionVocabulary::QUOTATION_VIEW]);
        } catch (AuthorizationException|DomainInvariantException $refusal) {
            // Holding a duty but not seeing the record is "not found"; it outranks "holds no such duty", so the answer never differs by which path was tried first.
            $failure = $failure instanceof DomainInvariantException ? $failure : $refusal;
        }
        $revision = $preparer ?? $decider;
        if ($revision === null) {
            throw $failure ?? new AuthorizationException('You are not permitted to access quotations.');
        }

        return [$revision, $preparer !== null, $decider !== null];
    }

    private function recordedNeed(QuotationRevision $revision): bool
    {
        return DB::table('quotation_draft_lines')->where('revision_id', $revision->id)->where('approval_required', true)->exists()
            || DB::table('quotation_draft_document_discounts')->where('revision_id', $revision->id)->where('approval_required', true)->exists();
    }

    /** @return array{id: int, name: string}|null */
    private function person(int $userId): ?array
    {
        $name = User::query()->whereKey($userId)->value('name');

        return $name === null ? null : ['id' => $userId, 'name' => (string) $name];
    }
}

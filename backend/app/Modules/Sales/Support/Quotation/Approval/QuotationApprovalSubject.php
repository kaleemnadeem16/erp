<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Approval;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\ResolveApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ProtectedApprovalEvidenceSubject;
use App\Modules\Approvals\Support\RecordScopedApprovalSubject;
use App\Modules\Approvals\Support\StrictApprovalSubject;
use App\Modules\Approvals\Support\TransactionalApprovalSubject;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityParticipants;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityRefusal;
use App\Modules\Sales\Support\DiscountAuthority\DiscountConfiguration;
use App\Modules\Sales\Support\DiscountAuthority\QuotationAuthorityApproval;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Sales approval adapter. ENFORCE opts into evaluated concession routing, effective-date/tie
 * refusal and named employee authority. The transaction hook precedes every engine request
 * lock. OFF/SHADOW retain the earlier concession approval rules.
 */
final class QuotationApprovalSubject implements ProtectedApprovalEvidenceSubject, RecordScopedApprovalSubject, StrictApprovalSubject, TransactionalApprovalSubject
{
    public const CODE = 'QUOTATION_REVISION';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Quotation discount or price override';
    }

    public function approvePermission(): string
    {
        return PermissionVocabulary::QUOTATION_APPROVE;
    }

    public function facts(): array
    {
        return [
            'amount_base' => ['label' => 'Evaluated discount in company base currency', 'type' => ApprovalFactType::Decimal],
            'authority_rank' => ['label' => 'Lowest qualified configured authority rank', 'type' => ApprovalFactType::Id],
            'concessions' => ['label' => 'Kinds of concession (DISCOUNT, OVERRIDE, DISCOUNT+OVERRIDE)', 'type' => ApprovalFactType::String],
            'currency_id' => ['label' => 'Quotation currency', 'type' => ApprovalFactType::Id],
        ];
    }

    /** Nothing monetary is routed or shown, so there is no figure to guard; the summary is the same for everyone. */
    public function canViewEvidence(User $user): bool
    {
        $current = ActiveActor::current($user);

        return $current !== null && ! $current->must_change_password && SalesAccess::may($current, PermissionVocabulary::AUTHORITY_EVIDENCE_VIEW);
    }

    public function routing(int $subjectId): ApprovalRouting
    {
        $revision = QuotationRevision::query()->findOrFail($subjectId);

        $assessment = $this->strictPolicySelection() ? app(QuotationAuthorityApproval::class)->assessment($revision) : null;
        $extra = $assessment === null ? [] : ['authority_fingerprint' => $assessment['fingerprint'], 'authority_rank' => $assessment['required_rank']];

        return new ApprovalRouting(
            $extra + [
                'concessions' => QuotationContentFingerprint::concessions($revision), 'currency_id' => $revision->currency_id,
                'input_generation' => $revision->input_generation, 'content_fingerprint' => QuotationContentFingerprint::of($revision),
            ],
            $assessment['measure']['reduction_base_amount'] ?? null,
            ['authority' => $assessment, 'fingerprint_version' => QuotationContentFingerprint::VERSION],
            ['number' => "Quotation draft r{$revision->revision_ordinal} (#{$revision->id})", 'title' => 'Discount or price override'],
        );
    }

    public function mayDecide(User $person, ApprovalRequest $request, bool $delegated): bool
    {
        $person = ActiveActor::current($person);
        if ($person === null) {
            return false;
        }
        if ($delegated || $request->submitted_by === $person->id) {
            return false;
        }
        $revisionId = $request->subject_id;
        if ($this->tookPart($person->id, $revisionId)) {
            return false;
        }
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->requireRevision($person, $revisionId, [PermissionVocabulary::QUOTATION_APPROVE, PermissionVocabulary::QUOTATION_VIEW]));
        } catch (AuthorizationException) {
            return false;
        } catch (DomainInvariantException $refusal) {
            if ($refusal->reasonCode === QuotationRefusal::QUOTATION_NOT_FOUND) {
                return false;
            }
            throw $refusal;
        }

        if ($this->requestHasExpired($request) || ! hash_equals($request->facts_hash, $this->routing($revisionId)->hash())) {
            return false;
        }
        if ($this->strictPolicySelection()) {
            return app(QuotationAuthorityApproval::class)->mayDecide($person, QuotationRevision::query()->findOrFail($revisionId));
        }

        return true;
    }

    public function mayReadRequestEvidence(User $actor, ApprovalRequest $request): bool
    {
        if (! $this->canViewEvidence($actor)) {
            return false;
        }
        try {
            DB::transaction(fn () => app(QuotationAccess::class)->requireRevision($actor, $request->subject_id, [PermissionVocabulary::QUOTATION_VIEW]));
        } catch (AuthorizationException|DomainInvariantException) {
            return false;
        }

        return true;
    }

    public function strictPolicySelection(): bool
    {
        return app(QuotationAuthorityApproval::class)->enabled();
    }

    public function policySelectionDate(): string
    {
        return app(DiscountConfiguration::class)->today();
    }

    /** @param \Closure(User): (ApprovalRequest|null) $work */
    public function approvalTransaction(int $subjectId, User $actor, \Closure $work, string $operation): ?ApprovalRequest
    {
        return DB::transaction(function () use ($subjectId, $actor, $work, $operation): ?ApprovalRequest {
            app(CompanyAccessFence::class)->lockAll();
            app(AuthorityFence::class)->lock();
            $fresh = ActiveActor::require($actor, 'decide quotation approvals');
            app(QuotationAccess::class)->requireRevision($fresh, $subjectId, [PermissionVocabulary::QUOTATION_VIEW, $operation === 'decide' ? PermissionVocabulary::QUOTATION_APPROVE : PermissionVocabulary::QUOTATION_PREPARE], true);

            return $work($fresh);
        });
    }

    public function submissionSnapshot(int $subjectId, ApprovalRouting $routing): array
    {
        $snapshot = $routing->snapshot();
        if ($this->strictPolicySelection()) {
            $snapshot['evidence']['authority_expires_at'] = app(QuotationAuthorityApproval::class)->deadline(QuotationRevision::query()->findOrFail($subjectId));
        }

        return $snapshot;
    }

    public function decisionEvidence(User $actor, ApprovalRequest $request): array
    {
        if (! $this->strictPolicySelection()) {
            return [];
        }
        $revision = QuotationRevision::query()->findOrFail($request->subject_id);
        $policy = app(DiscountConfiguration::class)->policy($revision);
        $fresh = ActiveActor::require($actor, 'record reviewer authority');
        $assignment = app(DiscountConfiguration::class)->assignment($fresh, $revision, $policy, 'APPROVE');

        return ['sales_actor_authorization_generation' => (int) $fresh->getAttribute('authorization_generation'), 'sales_authority_policy_id' => $policy->id, 'sales_authority_assignment_id' => $assignment?->id,
            'sales_authority_band_id' => $assignment?->band_id, 'sales_authority_fingerprint' => app(QuotationAuthorityApproval::class)->assessment($revision)['fingerprint']];
    }

    public function requestHasExpired(ApprovalRequest $request): bool
    {
        return app(QuotationAuthorityApproval::class)->expired($request);
    }

    public function assertRequestCurrent(ApprovalRequest $request): void
    {
        app(QuotationAuthorityApproval::class)->assertUnexpired($request);
        if ($this->strictPolicySelection()) {
            $policy = app(ResolveApprovalPolicy::class)(self::CODE, $this->routing($request->subject_id));
            if ($policy?->id !== $request->policy_id) {
                throw AuthorityRefusal::make('AUTHORITY_APPROVAL_POLICY_CHANGED', 'Approval routing changed. Submit the current quotation for review again.');
            }
        }
    }

    /** The person who created the draft or entered any concession on it prepared it: they can never approve it. */
    public function tookPart(int $userId, int $revisionId): bool
    {
        $revision = QuotationRevision::query()->find($revisionId);
        if ($revision !== null && DB::table('quotation_concession_participants')->where('company_id', $revision->company_id)->where('revision_id', $revisionId)->where('actor_id', $userId)->exists()) {
            return true;
        }
        if ($this->strictPolicySelection()) {
            $revision = QuotationRevision::query()->find($revisionId);
            if ($revision !== null && in_array($userId, app(AuthorityParticipants::class)->ids($revision), true)) {
                return true;
            }
        }
        if (DB::table('quotation_revisions')->where('id', $revisionId)->where('created_by', $userId)->exists()) {
            return true;
        }
        if (DB::table('quotation_draft_lines')->where('revision_id', $revisionId)->where(fn ($q) => $q->where('discount_entered_by', $userId)->orWhere('override_entered_by', $userId))->exists()) {
            return true;
        }

        return DB::table('quotation_draft_document_discounts')->where('revision_id', $revisionId)->where('entered_by', $userId)->exists();
    }
}

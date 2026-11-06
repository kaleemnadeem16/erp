<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Approval;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\RecordScopedApprovalSubject;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * How a quotation draft tells the approval engine what it is (ADR 0070, ADR 0158). The subject is one REVISION. The routed facts are the kinds of concession the draft carries
 * and its currency; the content fingerprint and generation ride in the same hashed facts so the engine's own "document changed" rule becomes "this exact draft changed".
 * No amount is routed (no base-currency conversion evidence exists for a concession yet), so a step that uses an authority ceiling can never be satisfied: default-deny.
 * The summary names no recipient, price or limit. Beyond the engine's capability rule, the record policy decides who may decide: the quotation-family scope (OWN or ALL) with
 * the independent view, never the draft's creator or the person who entered a concession on it, and never a delegate (the owner has not decided delegation).
 */
final class QuotationApprovalSubject implements RecordScopedApprovalSubject
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
            'concessions' => ['label' => 'Kinds of concession (DISCOUNT, OVERRIDE, DISCOUNT+OVERRIDE)', 'type' => ApprovalFactType::String],
            'currency_id' => ['label' => 'Quotation currency', 'type' => ApprovalFactType::Id],
        ];
    }

    /** Nothing monetary is routed or shown, so there is no figure to guard; the summary is the same for everyone. */
    public function canViewEvidence(User $user): bool
    {
        return false;
    }

    public function routing(int $subjectId): ApprovalRouting
    {
        $revision = QuotationRevision::query()->findOrFail($subjectId);

        return new ApprovalRouting(
            [
                'concessions' => QuotationContentFingerprint::concessions($revision), 'currency_id' => $revision->currency_id,
                'input_generation' => $revision->input_generation, 'content_fingerprint' => QuotationContentFingerprint::of($revision),
            ],
            null,
            ['fingerprint_version' => QuotationContentFingerprint::VERSION],
            ['number' => "Quotation draft r{$revision->revision_ordinal} (#{$revision->id})", 'title' => 'Discount or price override'],
        );
    }

    public function mayDecide(User $person, ApprovalRequest $request, bool $delegated): bool
    {
        if ($delegated) {
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

        return true;
    }

    /** The person who created the draft or entered any concession on it prepared it: they can never approve it. */
    public function tookPart(int $userId, int $revisionId): bool
    {
        if (DB::table('quotation_revisions')->where('id', $revisionId)->where('created_by', $userId)->exists()) {
            return true;
        }
        if (DB::table('quotation_draft_lines')->where('revision_id', $revisionId)->where(fn ($q) => $q->where('discount_entered_by', $userId)->orWhere('override_entered_by', $userId))->exists()) {
            return true;
        }

        return DB::table('quotation_draft_document_discounts')->where('revision_id', $revisionId)->where('entered_by', $userId)->exists();
    }
}

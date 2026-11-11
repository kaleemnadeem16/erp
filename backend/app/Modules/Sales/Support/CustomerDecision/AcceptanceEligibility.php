<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Models\User;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Models\CustomerScopeClaimHead;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationLogicalScope;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationAvailability;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\CurrentCompany;

/** @internal Full-scope eligibility, shared by preview/record; must run inside a transaction. */
final class AcceptanceEligibility
{
    public static function early(User $actor): void
    {
        CustomerDecisionAccess::early($actor, P::CUSTOMER_DECISION_ACCEPT, 'accept customer decision');
        SalesAccess::require($actor, P::CUSTOMER_DECISION_VIEW, 'see customer decisions');
    }

    /** @param array<string, mixed> $intent */
    public function lock(User $actor, array $intent): AcceptanceSubject
    {
        // DecisionActor::require is called by decide BEFORE any authority/record lock or receipt read.
        $fresh = CustomerDecisionAccess::decide($actor, P::CUSTOMER_DECISION_ACCEPT, 'accept customer decision');
        SalesAccess::require($fresh, P::CUSTOMER_DECISION_VIEW, 'see customer decisions');
        app(AuthorityFence::class)->lock();
        $component = CustomerDecisionComponent::query()->findOrFail($intent['component_id']);
        $case = CustomerDecisionCase::query()->lockForUpdate()->findOrFail($component->case_id);
        CustomerScopeClaimHead::query()->where('family_id', $component->family_id)->orderBy('id')->lockForUpdate()->get();
        $revision = app(QuotationAccess::class)->requireRevision($fresh, $intent['revision_id'], [P::QUOTATION_VIEW], true, false);
        $line = QuotationIssuedLine::query()->where('revision_id', $revision->id)->findOrFail($intent['issued_line_id']);
        if ($component->family_id !== $revision->family_id || $case->party_id !== $revision->recipient_party_id
            || QuotationLogicalScope::query()->findOrFail($line->logical_scope_id)->effectiveAnchorId() !== $component->original_scope_anchor_id) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::ACCEPTANCE_NOT_ELIGIBLE, 'The consent component must identify this customer and original issued scope.');
        }

        return new AcceptanceSubject($case, $component, $revision, $line);
    }

    /** @param array<string, mixed> $intent */
    public function require(AcceptanceSubject $s, array $intent): void
    {
        if (QuotationAvailability::of($s->revision) !== QuotationAvailability::AVAILABLE
            || $s->revision->expires_at === null || now()->greaterThanOrEqualTo($s->revision->expires_at)
            || now(app(CurrentCompany::class)->timezone())->toDateString() > $s->revision->valid_through_local_date?->toDateString()) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::QUOTATION_NOT_AVAILABLE, 'Only a currently available issued offer may be accepted.');
        }
        if ($s->case->generation !== $intent['expected_case_generation']) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::CASE_STALE, 'The case changed; review the current observations.', 'expected_case_generation');
        }
        $evidence = CustomerDecisionEvidence::query()->where('case_id', $s->case->id)->find($intent['evidence_id']);
        if ($evidence === null || $evidence->id !== CustomerDecisionEvidence::query()->where('case_id', $s->case->id)->max('id')
            || $evidence->source_kind === 'VERBAL' || ! in_array($evidence->authority_verification_basis, ['DOCUMENT_ON_FILE', 'CONFIRMED_BY_SELLER'], true)
            || trim((string) $evidence->customer_representative_snapshot) === '') {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::ACCEPTANCE_NOT_ELIGIBLE, 'Current written consent with a named representative and verified authority is required.', 'evidence_id');
        }
        $head = CustomerScopeClaimHead::query()->where('scope_anchor_id', $s->component->original_scope_anchor_id)->first();
        if (($head->consumed ?? 0) !== 0) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::SCOPE_ALREADY_CLAIMED, 'This scope is already claimed.');
        }
        if (($head->generation ?? 0) !== $intent['expected_scope_generation']) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::PREVIEW_TOKEN_STALE, 'The scope changed; review it again.');
        }
        $choice = $s->line->choice_group_id === null ? null : CustomerScopeClaimHead::query()->where('choice_group_id', $s->line->choice_group_id)->first();
        if (($choice->consumed ?? 0) !== 0) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::CHOICE_ALREADY_SELECTED, 'A choice in this group is already selected.');
        }
        if (($s->line->choice_group_id === null && $intent['expected_choice_generation'] !== -1)
            || ($s->line->choice_group_id !== null && $intent['expected_choice_generation'] !== ($choice->generation ?? 0))) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::PREVIEW_TOKEN_STALE, 'The choice group changed; review it again.');
        }
    }

    /** @return list<CustomerScopeClaimHead> */
    public function baselines(AcceptanceSubject $s): array
    {
        $heads = [CustomerScopeClaimHead::firstOrCreate(['scope_anchor_id' => $s->component->original_scope_anchor_id], ['family_id' => $s->component->family_id])];
        if ($s->line->choice_group_id !== null) {
            $heads[] = CustomerScopeClaimHead::firstOrCreate(['choice_group_id' => $s->line->choice_group_id], ['family_id' => $s->component->family_id]);
        }
        usort($heads, fn (CustomerScopeClaimHead $a, CustomerScopeClaimHead $b): int => $a->id <=> $b->id);

        return $heads;
    }
}

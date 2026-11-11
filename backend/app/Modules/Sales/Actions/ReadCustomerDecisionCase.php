<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionAccess;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\RecipientNames;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * One intake case with its components and the whole observation chain (oldest first; each observation says which earlier one it corrects, and whether it is the current head of
 * its chain). Needs `sales.customer-decision.view` (STAGED), decided under the company access fence before the case is looked up, so a caller without it gets the same 403 whether
 * or not the case exists. A component is shown only where the actor may also see its quotation family (`sales.quotation.view`); the rest are counted, never described. The
 * observations remain textual statements. The consent flag derives only from authoritative acceptances of components the caller may independently see.
 *
 * @phpstan-type Detail array{case: array<string, mixed>, components: list<array<string, mixed>>, hidden_component_count: int, observations: list<array<string, mixed>>}
 */
final class ReadCustomerDecisionCase
{
    /** @return Detail */
    public function __invoke(User $actor, int $caseId): array
    {
        CustomerDecisionAccess::early($actor, PermissionVocabulary::CUSTOMER_DECISION_VIEW, 'see customer decisions');

        return DB::transaction(function () use ($actor, $caseId): array {
            $fresh = CustomerDecisionAccess::decide($actor, PermissionVocabulary::CUSTOMER_DECISION_VIEW, 'see customer decisions');
            $case = CustomerDecisionCase::query()->with(['components', 'evidence'])->find($caseId)
                ?? throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::CASE_NOT_FOUND, 'The decision case was not found.', 'case_id');

            $familyIds = $case->components->pluck('family_id')->unique()->values()->all();
            try {
                $visible = $familyIds === [] ? [] : app(QuotationAccess::class)->queryFor($fresh, [PermissionVocabulary::QUOTATION_VIEW])
                    ->whereIn('quotation_families.id', $familyIds)->pluck('quotation_families.id')->map(fn ($id): int => (int) $id)->all();
            } catch (AuthorizationException) {
                $visible = [];
            }
            $components = $case->components->filter(fn ($c): bool => in_array((int) $c->family_id, $visible, true))->sortBy('id')->values();

            $names = app(RecipientNames::class)->for($fresh, [(int) $case->party_id]);
            $people = User::query()->whereIn('id', $case->evidence->pluck('recorded_by')->push($case->recorded_by)->unique()->values()->all())->pluck('name', 'id');
            $successors = $case->evidence->whereNotNull('predecessor_id')->pluck('id', 'predecessor_id');

            return [
                'case' => [
                    'id' => (int) $case->id, 'party_id' => (int) $case->party_id, 'party_name' => $names[(int) $case->party_id] ?? null, 'intake_identity' => $case->intake_identity,
                    'generation' => (int) $case->generation, 'recorded_by' => (int) $case->recorded_by, 'recorded_by_name' => $people[$case->recorded_by] ?? null,
                    'recorded_at' => $case->recorded_at->toIso8601String(), 'consent_recorded' => CustomerAcceptance::query()->where('case_id', $case->id)->whereIn('decision_component_id', $case->components->whereIn('family_id', $visible)->modelKeys())->exists(),
                ],
                'components' => $components->map(fn ($c): array => [
                    'id' => (int) $c->id, 'intent_identity' => $c->intent_identity, 'family_id' => (int) $c->family_id, 'original_scope_anchor_id' => (int) $c->original_scope_anchor_id,
                    'recorded_at' => $c->recorded_at->toIso8601String(),
                ])->all(),
                'hidden_component_count' => $case->components->count() - $components->count(),
                'observations' => $case->evidence->sortBy('id')->values()->map(fn (CustomerDecisionEvidence $e): array => [
                    'id' => (int) $e->id, 'predecessor_id' => $e->predecessor_id === null ? null : (int) $e->predecessor_id, 'is_current' => ! isset($successors[$e->id]),
                    'source_kind' => $e->source_kind, 'external_reference' => $e->external_reference, 'source_version' => $e->source_version,
                    'customer_representative_snapshot' => $e->customer_representative_snapshot, 'authority_verification_basis' => $e->authority_verification_basis,
                    'occurrence_precision' => $e->occurrence_precision, 'occurred_at' => $e->occurred_at?->toIso8601String(), 'occurred_until' => $e->occurred_until?->toIso8601String(),
                    'source_timezone' => $e->source_timezone, 'factual_description' => $e->factual_description, 'observation_state' => $e->observation_state,
                    'recorded_by' => (int) $e->recorded_by, 'recorded_by_name' => $people[$e->recorded_by] ?? null, 'recorded_at' => $e->recorded_at->toIso8601String(),
                ])->all(),
            ];
        });
    }
}

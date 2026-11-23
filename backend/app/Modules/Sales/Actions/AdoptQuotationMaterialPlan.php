<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationMaterialPlan;
use App\Modules\Sales\Models\QuotationMaterialPlanApproval;
use App\Modules\Sales\Models\QuotationMaterialPlanItem;
use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\MaterialPlan\EvaluateMaterialPlan;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanInputs;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanStanding;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Pricing\PricingSourceKind;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationOperation;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Support\Facades\DB;

/**
 * Adopts the Material Plan of ONE Assembly line on a DRAFT quotation revision (ADR 0190, ADR 0054, ADR 0076, ADR 0122): the seller's planned quantities and hand-chosen stock are
 * evaluated (see `EvaluateMaterialPlan`) and, when nothing blocks them, stored as an immutable adoption fact with one row per leaf material — standard and planned quantity kept
 * apart, the policy and matched conditions, any reason, the chosen stock and its judgement — plus one approval subject per item whose control needs approval. A plan that is BLOCKED, that
 * lacks a required reason or that holds a mix which cannot be judged, is complete or is blocked is refused and nothing is stored. Adopting again supersedes the earlier adoption (a new
 * row; the old one stays) and ends its approvals.
 *
 * It stores and routes; it neither requests nor decides an approval, reserves, allocates or posts any stock (Phase 5). Guarded by the STAGED `sales.quotation.prepare` duty for the
 * revision, the person's own Estimating preview rights, and `estimating.material_plan.adjust` / `estimating.package_plan.adopt` for what they change. Idempotent by request key.
 */
final class AdoptQuotationMaterialPlan
{
    public function __construct(
        private readonly QuotationOperation $operations,
        private readonly EvaluateMaterialPlan $evaluate,
        private readonly MaterialPlanStanding $standing,
        private readonly InvalidateApprovals $invalidate,
    ) {}

    /**
     * @param  array<string, mixed>  $plan  customer_id, dimension_value_ids, planned_adjustments, stock_mix, stock_mix_notes, inventory_scope, selection_strategy
     * @return array{plan_id: int, effect: string, approvals_needed: int, replayed: bool}
     */
    public function __invoke(User $actor, int $revisionId, int $lineId, int $expectedGeneration, ?string $requestKey, array $plan): array
    {
        return DB::transaction(function () use ($actor, $revisionId, $lineId, $expectedGeneration, $requestKey, $plan): array {
            app(CompanyAccessFence::class)->lockAll();
            DB::transaction(fn () => app(QuotationAccess::class)->queryFor($actor, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE]));
            $inputs = MaterialPlanInputs::normalise($plan);
            $outcome = $this->operations->run(
                SalesActorContext::forUser($actor, 'quotation.adopt_material_plan'), 'quotation.adopt_material_plan', $requestKey,
                ['revision_id' => $revisionId, 'line_id' => $lineId, 'expected_generation' => $expectedGeneration, 'plan' => $inputs],
                fn (): QuotationRevision => app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true),
                fn (QuotationRevision $revision): array => $this->apply($actor, $revision, $lineId, $expectedGeneration, $inputs),
            );

            return ['plan_id' => (int) $outcome['result']['plan_id'], 'effect' => (string) $outcome['result']['effect'], 'approvals_needed' => (int) $outcome['result']['approvals_needed'], 'replayed' => $outcome['replayed']];
        });
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    private function apply(User $actor, QuotationRevision $revision, int $lineId, int $expectedGeneration, array $inputs): array
    {
        if (! $revision->isDraft()) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can adopt a material plan.', 'revision_id');
        }
        if ($revision->input_generation !== $expectedGeneration) {
            throw QuotationRefusal::make(QuotationRefusal::QUOTATION_DRAFT_STALE, "The draft is at generation {$revision->input_generation}, not {$expectedGeneration}; reload it before adopting a plan.", 'expected_generation');
        }
        $line = QuotationDraftLine::query()->where('revision_id', $revision->id)->whereKey($lineId)->lockForUpdate()->first()
            ?? throw QuotationRefusal::make(QuotationRefusal::LINE_NOT_FOUND, 'The line is not on this draft.', 'line_id');
        if ($line->sourceKind() !== PricingSourceKind::AssemblyWork) {
            throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_NOT_APPLICABLE, 'Only an Assembly line has a material plan.', 'line_id');
        }

        $evaluation = ($this->evaluate)($actor, $line, $inputs);
        $this->refuseWhatBlocks($evaluation->problemCodes());

        $previous = $this->standing->currentPlan($line->id);
        $plan = QuotationMaterialPlan::create([
            'family_id' => $revision->family_id, 'revision_id' => $revision->id, 'line_id' => $line->id, 'logical_scope_id' => $line->logical_scope_id, 'supersedes_id' => $previous?->id,
            'assembly_version_id' => $line->assembly_version_id, 'work_quantity' => $line->offered_quantity, 'work_uom_id' => $line->offered_uom_id, 'inputs' => $inputs,
            'recipient_party_id' => $evaluation->recipientPartyId, 'customer_id' => $evaluation->customerId, 'provenance' => $evaluation->provenance,
            'effect' => $evaluation->effect, 'routing_fingerprint' => $evaluation->fingerprint, 'adopted_by' => $actor->id,
        ]);
        $itemIds = [];
        foreach ($evaluation->items as $row) {
            $item = QuotationMaterialPlanItem::create(['revision_id' => $revision->id, 'plan_id' => $plan->id] + $row);
            $itemIds[$row['item_id']] = $item->id;
        }
        foreach ($evaluation->approvals as $approval) {
            QuotationMaterialPlanApproval::create(['revision_id' => $revision->id, 'plan_id' => $plan->id, 'plan_item_id' => $itemIds[$approval['item_id']],
                'subject_code' => $approval['subject_code'], 'facts' => $approval['facts']]);
        }
        if ($previous !== null) {
            foreach ($this->standing->approvals($previous) as $row) {
                $this->invalidate->forSubject($row->subject_code, $row->id, 'The material plan was adopted again; the earlier adoption is superseded.');
            }
        }

        return ['v' => 1, 'family_id' => $revision->family_id, 'plan_id' => $plan->id, 'effect' => $evaluation->effect, 'approvals_needed' => count($evaluation->approvals)];
    }

    /** @param  list<string>  $codes */
    private function refuseWhatBlocks(array $codes): void
    {
        $messages = [
            QuotationRefusal::MATERIAL_PLAN_BLOCKED => 'A planned quantity falls in a blocked band (or one the company cannot approve); correct the plan.',
            QuotationRefusal::MATERIAL_PLAN_REASON_REQUIRED => 'A planned difference needs a reason from the company list.',
            QuotationRefusal::STOCK_CHOICE_NOT_ADOPTABLE => 'A hand-chosen stock mix is blocked, still needs its reason, cannot be judged, or does not cover the plan; follow the proposal or correct the mix.',
        ];
        foreach ($messages as $code => $message) {
            if (in_array($code, $codes, true)) {
                throw QuotationRefusal::make($code, $message, 'plan');
            }
        }
    }
}

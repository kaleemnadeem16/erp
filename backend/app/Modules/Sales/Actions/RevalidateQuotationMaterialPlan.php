<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Models\QuotationMaterialPlanRevalidation;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\MaterialPlan\EvaluateMaterialPlan;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanStaleness;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanStanding;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Support\Facades\DB;

/**
 * The deliberate re-check of an adopted Material Plan (ADR 0122): evaluates the stored request again, now, and appends the outcome. `CURRENT` — the routing fingerprint is the same, so
 * the policy, quantities, controls and routing figures that were approved still hold and any approval stands unchanged; `CHANGED` — the line moved, or a policy, stock or cost
 * consequence did: the approvals are ended (history stays), the standing becomes STALE and the seller adopts the plan again. It never rewrites, rebinds or revives an approval and never
 * moves one to a successor plan. The evidence is append-only; a re-check that cannot run (a missing right, a withdrawn version) refuses and records nothing.
 */
final class RevalidateQuotationMaterialPlan
{
    public function __construct(private readonly MaterialPlanStanding $standing, private readonly EvaluateMaterialPlan $evaluate, private readonly MaterialPlanStaleness $staleness) {}

    /** @return array{outcome: string, change_codes: list<string>} */
    public function __invoke(User $actor, int $revisionId, int $lineId): array
    {
        return DB::transaction(function () use ($actor, $revisionId, $lineId): array {
            app(CompanyAccessFence::class)->lockAll();
            $actor = ActiveActor::require($actor, 'revalidate a material plan');
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true);
            if (! $revision->isDraft()) {
                throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can re-check a material plan.', 'revision_id');
            }
            $line = QuotationDraftLine::query()->where('revision_id', $revision->id)->whereKey($lineId)->first() ?? throw QuotationRefusal::make(QuotationRefusal::LINE_NOT_FOUND, 'The line is not on this draft.', 'line_id');
            $plan = $this->standing->currentPlan($line->id) ?? throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_NOT_FOUND, 'The line has no adopted material plan.', 'line_id');

            $codes = [];
            $fingerprint = $plan->routing_fingerprint;
            if (($moved = $this->standing->changedSince($plan, $line)) !== null) {
                $codes[] = $moved;
            } else {
                try {
                    $now = ($this->evaluate)($actor, $line, $plan->inputs);
                } catch (DomainInvariantException $refusal) {
                    if (in_array($refusal->reasonCode, [QuotationRefusal::MATERIAL_PLAN_VERSION_NOT_ACTIVE], true)) {
                        $now = null;
                        $codes[] = QuotationRefusal::MATERIAL_PLAN_VERSION_NOT_ACTIVE;
                    } else {
                        throw $refusal;
                    }
                }
                if ($now !== null) {
                    $fingerprint = $now->fingerprint;
                    if ($now->problems !== []) {
                        array_push($codes, ...$now->problemCodes());
                    } elseif ($now->fingerprint !== $plan->routing_fingerprint) {
                        $codes[] = 'ROUTING_FACTS_CHANGED';
                    }
                }
            }
            $outcome = $codes === [] ? 'CURRENT' : 'CHANGED';
            QuotationMaterialPlanRevalidation::create(['revision_id' => $revision->id, 'plan_id' => $plan->id, 'outcome' => $outcome, 'fingerprint' => $fingerprint,
                'change_codes' => $codes, 'checked_by' => $actor->id]);
            if ($outcome === 'CHANGED') {
                $this->staleness->invalidateApprovals($plan, 'The material plan was re-checked and no longer holds; adopt it again.');
            }

            return ['outcome' => $outcome, 'change_codes' => $codes];
        });
    }
}

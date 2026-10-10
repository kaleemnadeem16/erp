<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Approvals\Actions\ResolveApprovalPolicy;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Support\CostReadAuthorization;
use App\Modules\Estimating\Support\MaterialVarianceApprovalSubject;
use App\Modules\Estimating\Support\MaterialVarianceEvaluation;
use App\Modules\Estimating\Support\MaterialVariancePolicyResolution;
use App\Modules\Estimating\Support\VarianceCostComparison;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceMeasure;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Support\ActionAuthorization;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The variance half of ADR 0058's preview: what-if planned quantities per leaf material, judged
 * against their calculated standards by ADR 0054's policy. Hypothetical and write-free — nothing is
 * adopted, audited or stored; Phase 4 owns adoption.
 *
 * A planned quantity is entered in any unit that converts to the item's stock unit and is judged in
 * the stock unit. Quantity precision is enforced like everywhere else (a fractional roll is refused,
 * not rounded). An unusable input — an item the assembly does not require, a retired reason, an
 * unconvertible unit — fails loudly rather than being ignored.
 *
 * Internal cost decides cost bands but is never returned to a caller without
 * `estimating.internal_cost.view`: the amounts a cost band observed are masked, and cost impact is
 * omitted, rather than reported as zero. Standard and planned quantities are costed as separate
 * hypothetical scenarios on one read snapshot; a genuine zero cost is evidence, while missing,
 * partial or unsupported evidence is never "free" and never passes a cost band.
 *
 * The same rows drive the inventory proposal: `planned` maps item id → planned stock quantity.
 */
final class PreviewMaterialVariance
{
    public function __construct(
        private readonly ResolveMaterialVariancePolicy $resolvePolicy,
        private readonly EvaluateMaterialVariance $evaluate,
        private readonly ItemUomConversionService $conversion,
        private readonly QueryMaterialCost $materialCost,
        private readonly CostReadAuthorization $costRead,
        private readonly ResolveApprovalPolicy $resolveApproval,
    ) {}

    /**
     * @param  list<array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}>  $leafRows
     * @param  list<array{item_id: int, planned_quantity: string, uom_id: int, reason_id?: int|null, note?: string|null}>  $adjustments
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: list<array<string, mixed>>} variance block, item id → planned stock quantity, fingerprint evidence
     */
    public function __invoke(array $leafRows, array $adjustments, User $actor): array
    {
        ActionAuthorization::require($actor, 'estimating.material_preview.run', 'evaluate material variance');
        if ($actor->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The actor is outside the active company.');
        }

        if ($actor->can('estimating.internal_cost.view')) {
            ($this->costRead)($actor);
        }

        $lease = $this->materialCost->observe();
        $session = $lease->session;
        try {
            $includeCost = $actor->can('estimating.internal_cost.view');
            $rowsByItem = [];
            foreach ($leafRows as $row) {
                $rowsByItem[$row['item']['id']] = $row;
            }

            $items = Item::query()->with(['stockUom', 'category.parent'])->whereIn('id', array_map(fn (array $a): int => $a['item_id'], $adjustments))->get()->keyBy('id');

            $rows = [];
            $planned = [];
            $evidence = [];
            $counts = ['needs_reason' => 0, 'blocked' => 0, 'adjusted' => 0, 'adoptable' => 0];
            $counts['needs_approval'] = 0;
            $costImpact = BigDecimal::zero();
            $costEvidenceMissing = 0;

            foreach ($adjustments as $index => $adjustment) {
                $itemId = $adjustment['item_id'];
                $row = $rowsByItem[$itemId] ?? null;
                $item = $items->get($itemId);
                if ($row === null || $item === null) {
                    throw new DomainInvariantException('This assembly does not require that material, so there is nothing to adjust.', "planned_adjustments.{$index}.item_id");
                }

                $reason = $this->reason($adjustment['reason_id'] ?? null, $index);
                $plannedStock = $this->plannedInStockUnit($item, $adjustment, $index);
                $standard = BigDecimal::of($row['standard_quantity']);

                $resolution = ($this->resolvePolicy)($item);
                // Cost decides a cost band and, for someone allowed to see it, the planned-cost figure; it is
                // looked up once for whichever needs it.
                $comparison = new VarianceCostComparison(
                    $this->materialCost->resolve(new MaterialCostRequest($item, $standard), new CostReadSession($session->observedAt)),
                    $this->materialCost->resolve(new MaterialCostRequest($item, $plannedStock), new CostReadSession($session->observedAt)),
                );
                $evaluation = ($this->evaluate)($item, $standard, $plannedStock, $resolution, $comparison);

                $status = $this->reasonStatus($evaluation, $reason);
                $adoptable = $evaluation->effect === null || $status === 'NOT_NEEDED' || $status === 'PROVIDED';
                $counts['needs_approval'] += $status === 'APPROVAL_NEEDED' ? 1 : 0;
                $counts['adjusted'] += $evaluation->isAdjusted() ? 1 : 0;
                $counts['needs_reason'] += $status === 'MISSING' ? 1 : 0;
                $counts['blocked'] += $evaluation->effect === VarianceEffect::Blocked ? 1 : 0;
                $counts['adoptable'] += $adoptable ? 1 : 0;

                $planned[$itemId] = (string) $plannedStock;
                $cost = $this->cost($comparison, $includeCost);
                if ($includeCost) {
                    if ($cost['status'] === 'AVAILABLE') {
                        $costImpact = $costImpact->plus($cost['delta']);
                    } else {
                        $costEvidenceMissing++;
                    }
                }
                $approval = $evaluation->effect === VarianceEffect::ApprovalRequired ? $this->approvalPreview($item, $comparison) : null;
                $rows[] = $this->row($item, $row, $adjustment, $evaluation, $resolution, $reason, $status, $adoptable, $includeCost, $cost, $approval);
                $evidence[] = [
                    'item_id' => $itemId, 'planned' => (string) $plannedStock, 'reason_id' => $reason?->id,
                    'policy_id' => $evaluation->policyId, 'effect' => $evaluation->effect?->value,
                ];
            }

            return [
                [
                    'items' => $rows,
                    'summary' => [
                        'adjusted_count' => $counts['adjusted'],
                        'needs_reason_count' => $counts['needs_reason'],
                        'blocked_count' => $counts['blocked'],
                        'needs_approval_count' => $counts['needs_approval'],
                        'can_adopt' => $counts['adoptable'] === count($rows),
                        // Estimated internal-cost effect of the whole plan: hidden (null) without the cost capability,
                        // and only over the materials that have cost evidence — never a zero standing in for "unknown".
                        'cost_impact' => $includeCost && $costEvidenceMissing === 0 ? (string) $costImpact->toScale(4, RoundingMode::HALF_UP) : null,
                        'known_cost_impact' => $includeCost && $costEvidenceMissing < count($rows) ? (string) $costImpact->toScale(4, RoundingMode::HALF_UP) : null,
                        'cost_status' => ! $includeCost ? 'HIDDEN' : ($costEvidenceMissing === 0 ? 'COSTED' : ($costEvidenceMissing < count($rows) ? 'PARTIAL' : 'UNKNOWN')),
                        'cost_evidence_missing_count' => $includeCost ? $costEvidenceMissing : null,
                    ],
                    'limitations' => $this->limitations(),
                ],
                $planned,
                $evidence,
            ];
        } finally {
            $lease->close();
        }
    }

    /** @return list<string> */
    private function limitations(): array
    {
        $limitations = [];
        $limitations[] = 'Hypothetical: nothing here is saved, audited or reserved. Adopting a plan belongs to a quotation revision (Phase 4), which re-evaluates it.';
        $limitations[] = 'Approval-required rules and waste bands are not available yet, so they are neither offered nor evaluated.';

        return $limitations;
    }

    private function reason(?int $reasonId, int $index): ?MaterialVarianceReason
    {
        if ($reasonId === null) {
            return null;
        }
        $reason = MaterialVarianceReason::query()->find($reasonId);
        if ($reason === null || ! $reason->active) {
            throw new DomainInvariantException('That reason has been retired; choose one that is still offered.', "planned_adjustments.{$index}.reason_id");
        }

        return $reason;
    }

    /**
     * @param  array{planned_quantity: string, uom_id: int}  $adjustment
     */
    private function plannedInStockUnit(Item $item, array $adjustment, int $index): BigDecimal
    {
        $uom = Uom::query()->findOrFail($adjustment['uom_id']);
        try {
            $factor = $this->conversion->stockUnitFactor($item, $uom);
        } catch (InvalidArgumentException) {
            throw new DomainInvariantException("{$uom->code} does not convert to {$item->code}'s stock unit ({$item->stockUom->code}).", "planned_adjustments.{$index}.uom_id");
        }
        $planned = BigDecimal::of($adjustment['planned_quantity'])->multipliedBy($factor);

        if ($item->quantity_precision !== null) {
            $precision = BigDecimal::of($item->quantity_precision);
            if (! $planned->remainder($precision)->isZero()) {
                throw new DomainInvariantException("{$item->code} can only be planned in multiples of {$precision->stripTrailingZeros()} {$item->stockUom->code}.", "planned_adjustments.{$index}.planned_quantity");
            }
        }

        return $planned->toScale(9, RoundingMode::HALF_UP);
    }

    private function reasonStatus(MaterialVarianceEvaluation $evaluation, ?MaterialVarianceReason $reason): string
    {
        return match ($evaluation->effect) {
            null, VarianceEffect::AuditOnly => 'NOT_NEEDED',
            VarianceEffect::ReasonRequired => $reason !== null ? 'PROVIDED' : 'MISSING',
            VarianceEffect::ApprovalRequired => 'APPROVAL_NEEDED',
            default => 'NOT_ACCEPTED',
        };
    }

    /**
     * Which approval a plan would need, if its difference falls in an approval-required band (ADR 0070 §11): the policy the
     * engine would route it under and its steps. Read-only — a plan is hypothetical until Phase 4 adopts it, so nothing is
     * submitted. With no matching policy it says so: an approval-required band never falls through to "not required".
     *
     * @return array{policy: array{id: int, code: string, version_no: int, name: string}|null, steps: list<array{name: string, stage: int, required_approvals: int, uses_authority_limit: bool}>, note: string|null}
     */
    private function approvalPreview(Item $item, VarianceCostComparison $comparison): array
    {
        $amount = $comparison->delta() === null ? null : (string) $comparison->delta()->abs()->toScale(4, RoundingMode::HALF_UP);
        $routing = new ApprovalRouting(['item_id' => $item->id, 'category_id' => $item->category_id], $amount, [], []);
        $policy = ($this->resolveApproval)(MaterialVarianceApprovalSubject::CODE, $routing);
        if ($policy === null) {
            return ['policy' => null, 'steps' => [], 'note' => 'No approval policy applies to this difference, so it cannot be adopted until one is configured.'];
        }

        return [
            'policy' => ['id' => $policy->id, 'code' => $policy->code, 'version_no' => $policy->version_no, 'name' => $policy->name],
            'steps' => $policy->steps->map(fn ($s): array => ['name' => $s->name, 'stage' => $s->stage, 'required_approvals' => $s->required_approvals, 'uses_authority_limit' => $s->use_authority_limit])->values()->all(),
            'note' => $amount === null ? 'There is no cost evidence for this material, so amount-based approval conditions could not be applied.' : null,
        ];
    }

    /**
     * The internal cost of the standard and of the plan, in the item's stock unit at its current unit cost.
     * `HIDDEN` without the cost capability and `NO_EVIDENCE` when the item has no defensible unit cost: in
     * both the amounts are null — never zero — so a missing figure cannot read as "free".
     *
     * @return array{status: string, standard: ?BigDecimal, planned: ?BigDecimal, delta: ?BigDecimal}
     */
    private function cost(VarianceCostComparison $comparison, bool $includeCost): array
    {
        if (! $includeCost) {
            return ['status' => 'HIDDEN', 'standard' => null, 'planned' => null, 'delta' => null];
        }

        return ['status' => $comparison->delta() === null ? 'NO_EVIDENCE' : 'AVAILABLE',
            'standard' => $comparison->standard->totalCost, 'planned' => $comparison->planned->totalCost,
            'delta' => $comparison->delta()];
    }

    /**
     * @param  array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}  $row
     * @param  array{planned_quantity: string, uom_id: int, note?: string|null}  $adjustment
     * @param  array{status: string, standard: ?BigDecimal, planned: ?BigDecimal, delta: ?BigDecimal}  $cost
     * @param  array{policy: array{id: int, code: string, version_no: int, name: string}|null, steps: list<array{name: string, stage: int, required_approvals: int, uses_authority_limit: bool}>, note: string|null}|null  $approval
     * @return array<string, mixed>
     */
    private function row(Item $item, array $row, array $adjustment, MaterialVarianceEvaluation $evaluation, MaterialVariancePolicyResolution $resolution, ?MaterialVarianceReason $reason, string $status, bool $adoptable, bool $includeCost, array $cost, ?array $approval): array
    {
        $matched = [];
        foreach ($evaluation->matched as $condition) {
            // What a cost band observed is internal cost: hidden, never zero, without the cost capability.
            if (! $includeCost && in_array($condition['measure'], [VarianceMeasure::CostAmount->value, VarianceMeasure::CostPercent->value], true)) {
                $condition['observed'] = null;
            }
            // Spelled out (not passed through) so the generated API schema carries each field's type.
            $matched[] = [
                'source' => (string) $condition['source'],
                'band_id' => $condition['band_id'] === null ? null : (int) $condition['band_id'],
                'direction' => $condition['direction'] === null ? null : (string) $condition['direction'],
                'measure' => $condition['measure'] === null ? null : (string) $condition['measure'],
                'threshold' => $condition['threshold'] === null ? null : (string) $condition['threshold'],
                'unit' => $condition['unit'] === null ? null : (string) $condition['unit'],
                'observed' => $condition['observed'] === null ? null : (string) $condition['observed'],
                'effect' => (string) $condition['effect'],
            ];
        }

        $submittedUom = Uom::query()->findOrFail($adjustment['uom_id']);

        return [
            'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name],
            'uom' => ['id' => (int) $row['uom']['id'], 'code' => (string) $row['uom']['code'], 'name' => (string) $row['uom']['name']],
            'standard_quantity' => (string) $evaluation->standard,
            'planned_quantity' => (string) $evaluation->planned,
            'entered' => ['quantity' => (string) $adjustment['planned_quantity'], 'uom_code' => $submittedUom->code],
            'delta_quantity' => (string) $evaluation->delta,
            'delta_percent' => $evaluation->deltaPercent === null ? null : (string) $evaluation->deltaPercent,
            'direction' => $evaluation->direction?->value,
            'effect' => $evaluation->effect?->value,
            'effect_label' => $evaluation->effect?->label(),
            'reason_status' => $status,
            'reason' => $reason === null ? null : ['id' => $reason->id, 'code' => $reason->code, 'name' => $reason->name],
            'note' => $adjustment['note'] ?? null,
            'can_adopt' => (bool) $adoptable,
            'approval' => $approval === null ? null : [
                'policy' => $approval['policy'] === null ? null : ['id' => (int) $approval['policy']['id'], 'code' => (string) $approval['policy']['code'], 'version_no' => (int) $approval['policy']['version_no'], 'name' => (string) $approval['policy']['name']],
                'steps' => array_map(fn (array $step): array => ['name' => (string) $step['name'], 'stage' => (int) $step['stage'], 'required_approvals' => (int) $step['required_approvals'], 'uses_authority_limit' => (bool) $step['uses_authority_limit']], $approval['steps']),
                'note' => $approval['note'] === null ? null : (string) $approval['note'],
            ],
            'cost' => [
                'status' => (string) $cost['status'],
                'standard' => $cost['standard'] === null ? null : (string) $cost['standard'],
                'planned' => $cost['planned'] === null ? null : (string) $cost['planned'],
                'delta' => $cost['delta'] === null ? null : (string) $cost['delta'],
            ],
            'policy' => $resolution->policy === null ? null : [
                'id' => $resolution->policy->id, 'scope_type' => $resolution->policy->scope_type->value,
                'scope_id' => $resolution->policy->scope_id, 'method' => $resolution->method->value,
            ],
            'matched' => $matched,
            'skipped' => $evaluation->skipped,
            'limitations' => $evaluation->limitations,
        ];
    }
}

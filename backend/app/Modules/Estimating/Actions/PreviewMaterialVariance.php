<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Support\MaterialVarianceEvaluation;
use App\Modules\Estimating\Support\MaterialVariancePolicyResolution;
use App\Modules\Estimating\Support\IngredientCostResolver;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceMeasure;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
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
 * omitted, rather than reported as zero. A zero unit cost is treated as no evidence, because "never
 * received" is not "free".
 *
 * The same rows drive the inventory proposal: `planned` maps item id → planned stock quantity.
 */
final class PreviewMaterialVariance
{
    public function __construct(
        private readonly ResolveMaterialVariancePolicy $resolvePolicy,
        private readonly EvaluateMaterialVariance $evaluate,
        private readonly ItemUomConversionService $conversion,
        private readonly IngredientCostResolver $ingredientCost,
    ) {}

    /**
     * @param  list<array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}>  $leafRows
     * @param  list<array{item_id: int, planned_quantity: string, uom_id: int, reason_id?: int|null, note?: string|null}>  $adjustments
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: list<array<string, mixed>>} variance block, item id → planned stock quantity, fingerprint evidence
     */
    public function __invoke(array $leafRows, array $adjustments, bool $includeCost): array
    {
        $rowsByItem = [];
        foreach ($leafRows as $row) {
            $rowsByItem[$row['item']['id']] = $row;
        }

        $items = Item::query()->with(['stockUom', 'category.parent'])->whereIn('id', array_map(fn (array $a): int => $a['item_id'], $adjustments))->get()->keyBy('id');

        $rows = [];
        $planned = [];
        $evidence = [];
        $counts = ['needs_reason' => 0, 'blocked' => 0, 'adjusted' => 0, 'adoptable' => 0];

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
            $evaluation = ($this->evaluate)($item, $standard, $plannedStock, $resolution, $this->unitCostIfNeeded($item, $resolution));

            $status = $this->reasonStatus($evaluation, $reason);
            $adoptable = $evaluation->effect === null || $status === 'NOT_NEEDED' || $status === 'PROVIDED';
            $counts['adjusted'] += $evaluation->isAdjusted() ? 1 : 0;
            $counts['needs_reason'] += $status === 'MISSING' ? 1 : 0;
            $counts['blocked'] += $evaluation->effect === VarianceEffect::Blocked ? 1 : 0;
            $counts['adoptable'] += $adoptable ? 1 : 0;

            $planned[$itemId] = (string) $plannedStock;
            $rows[] = $this->row($item, $row, $adjustment, $evaluation, $resolution, $reason, $status, $adoptable, $includeCost);
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
                    'can_adopt' => $counts['adoptable'] === count($rows),
                ],
                'limitations' => $this->limitations(),
            ],
            $planned,
            $evidence,
        ];
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
            default => 'NOT_ACCEPTED',
        };
    }

    /**
     * Cost is only fetched when the resolved policy has a cost band (the resolver is AVCO-company-pool
     * only and throws otherwise). No evidence — a non-AVCO item, or nothing ever received — is null,
     * so the cost band is reported skipped instead of measuring against a made-up zero.
     */
    private function unitCostIfNeeded(Item $item, MaterialVariancePolicyResolution $resolution): ?BigDecimal
    {
        $hasCostBand = $resolution->policy !== null && $resolution->policy->bands->contains(
            fn ($band): bool => in_array($band->measure, [VarianceMeasure::CostAmount, VarianceMeasure::CostPercent], true),
        );
        if (! $hasCostBand) {
            return null;
        }

        try {
            $cost = ($this->ingredientCost)($item);
        } catch (DomainInvariantException) {
            return null;
        }

        return $cost->isGreaterThan(0) ? $cost : null;
    }

    /**
     * @param  array{item: array{id: int, code: string, name: string}, standard_quantity: string, uom: array{id: int, code: string, name: string}}  $row
     * @param  array{planned_quantity: string, uom_id: int, note?: string|null}  $adjustment
     * @return array<string, mixed>
     */
    private function row(Item $item, array $row, array $adjustment, MaterialVarianceEvaluation $evaluation, MaterialVariancePolicyResolution $resolution, ?MaterialVarianceReason $reason, string $status, bool $adoptable, bool $includeCost): array
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

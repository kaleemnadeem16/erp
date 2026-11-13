<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\MaterialPlan;

use App\Models\User;
use App\Modules\Estimating\Actions\PreviewAssemblyMaterials;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyVersionStatus;
use App\Modules\Estimating\Support\PlanRoutingCollector;
use App\Modules\Estimating\Support\StockMixVerdict;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Inventory\Models\Item;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\ActionAuthorization;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Evaluates the Material Plan of ONE quotation Assembly line (ADR 0190) by composing Phase 3's read-only resolution — the Assembly version the line pins, its work quantity, the
 * seller's planned quantities and hand-chosen stock — and judging it exactly as the preview does (ADR 0054 variance bands, ADR 0076 stock-mix control). Nothing is written here;
 * Adopt, Revalidate and the issue assessor all call it, so adoption and re-checking can never disagree about what a plan is.
 *
 * It runs AS the person: the preview's own permissions apply (`estimating.material_preview.run`; availability for a mix), plus the dedicated adjustment and package-adoption
 * permissions. It does not need the internal-cost capability: the real cost figures the approval routing needs come through Estimating's sealed `PlanRoutingCollector`, and
 * only the fields the evidence keeps are copied out — what the person sees is what the preview shows them. The fingerprint is the same whoever asks: it covers quantities,
 * effects, policy identity, reasons, the chosen stock and the routing figures, never free text, so an editorial note does not invalidate an approval (ADR 0054 §6).
 *
 * @internal Only the plan Actions and the issue assessor call it (`PlanRoutingCollectorHasOneCallerTest` pins the collector's callers).
 */
final class EvaluateMaterialPlan
{
    public const VERSION = 1;

    public function __construct(private readonly PreviewAssemblyMaterials $preview) {}

    /**
     * @param  array<string, mixed>  $inputs  the output of `MaterialPlanInputs::normalise`
     * @param  bool  $adopting  false when only re-checking an adopted plan (the issue assessor): the person then needs the preview rights, not the right to change a plan
     */
    public function __invoke(User $actor, QuotationDraftLine $line, array $inputs, bool $adopting = true): MaterialPlanEvaluation
    {
        $version = AssemblyVersion::query()->with('assembly')->find($line->assembly_version_id);
        if ($version === null || $version->assembly->company_id !== $actor->company_id) {
            throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_NOT_APPLICABLE, 'The line has no Assembly version to plan.', 'line_id');
        }
        if ($version->status !== AssemblyVersionStatus::Active) {
            throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_VERSION_NOT_ACTIVE, 'The Assembly version this line prices is no longer active; choose the current version before planning materials.', 'line_id');
        }
        if ($adopting && ($inputs['planned_adjustments'] ?? []) !== []) {
            ActionAuthorization::require($actor, 'estimating.material_plan.adjust', 'adjust a material plan');
        }
        if ($adopting && ($inputs['stock_mix'] ?? []) !== []) {
            ActionAuthorization::require($actor, 'estimating.package_plan.adopt', 'adopt a hand-chosen stock mix');
        }

        $request = ['assembly_version_id' => $version->id, 'work_quantity' => (string) $line->offered_quantity, 'work_uom_id' => (int) $line->offered_uom_id,
            'dimension_value_ids' => $inputs['dimension_value_ids'] ?? []];
        foreach (['customer_id', 'inventory_scope', 'selection_strategy'] as $optional) {
            if (isset($inputs[$optional])) {
                $request[$optional] = $inputs[$optional];
            }
        }
        foreach (['planned_adjustments', 'stock_mix', 'stock_mix_notes'] as $list) {
            if (($inputs[$list] ?? []) !== []) {
                $request[$list] = $inputs[$list];
            }
        }
        $routing = new PlanRoutingCollector;
        $result = ($this->preview)($version->assembly, $request, $actor, $routing);

        $variance = [];
        foreach ($result['variance']['items'] ?? [] as $row) {
            $variance[(int) $row['item']['id']] = $row;
        }
        $inventory = [];
        foreach ($result['inventory']['items'] ?? [] as $row) {
            $inventory[(int) $row['item']['id']] = $row;
        }
        $categories = Item::query()->whereIn('id', array_map(fn (array $leaf): int => (int) $leaf['item']['id'], $result['leaf_requirements']))->pluck('category_id', 'id');

        $items = [];
        $problems = [];
        $approvals = [];
        $strongest = 'AUDIT_ONLY';
        foreach ($result['leaf_requirements'] as $leaf) {
            $itemId = (int) $leaf['item']['id'];
            $standard = BigDecimal::of((string) $leaf['standard_quantity'])->toScale(9, RoundingMode::HALF_UP);
            $v = $variance[$itemId] ?? null;
            $planned = $v === null ? $standard : BigDecimal::of((string) $v['planned_quantity'])->toScale(9, RoundingMode::HALF_UP);
            $effect = $v['effect'] ?? null;
            $amount = $routing->varianceAmount($itemId);
            if ($effect === VarianceEffect::Blocked->value || ($v['reason_status'] ?? null) === 'NOT_ACCEPTED') {
                $problems[] = ['code' => QuotationRefusal::MATERIAL_PLAN_BLOCKED, 'item_id' => $itemId];
            }
            if (($v['reason_status'] ?? null) === 'MISSING') {
                $problems[] = ['code' => QuotationRefusal::MATERIAL_PLAN_REASON_REQUIRED, 'item_id' => $itemId];
            }
            if ($effect === VarianceEffect::ApprovalRequired->value) {
                $approvals[] = ['item_id' => $itemId, 'subject_code' => 'MATERIAL_VARIANCE', 'facts' => ['item_id' => $itemId, 'category_id' => (int) ($categories[$itemId] ?? 0), 'amount_base' => $amount]];
            }

            $mix = $inventory[$itemId]['selected_mix'] ?? null;
            $mixRow = ['mix' => null, 'mix_level' => null, 'mix_status' => null, 'mix_note' => null, 'mix_percent' => null, 'mix_extra' => null];
            if ($mix !== null) {
                $status = (string) $mix['control']['status'];
                $figures = $routing->mixFigures($itemId) ?? ['percent' => null, 'extra' => null];
                $mixRow = [
                    'mix' => array_map(fn (array $l): array => ['warehouse_id' => (int) $l['warehouse']['id'], 'location_id' => (int) $l['location']['id'], 'batch_id' => $l['batch']['id'] ?? null, 'quantity' => (string) $l['quantity']], $mix['lines']),
                    'mix_level' => (string) $mix['control']['level']['code'], 'mix_status' => $status, 'mix_note' => $mix['control']['note'],
                    'mix_percent' => $figures['percent'], 'mix_extra' => $figures['extra'],
                ];
                if (in_array($status, [StockMixVerdict::BLOCKED, StockMixVerdict::REASON_MISSING, StockMixVerdict::NOT_JUDGED], true) || ! $mix['is_complete']) {
                    $problems[] = ['code' => QuotationRefusal::STOCK_CHOICE_NOT_ADOPTABLE, 'item_id' => $itemId];
                }
                if ($status === StockMixVerdict::APPROVAL_NEEDED) {
                    $approvals[] = ['item_id' => $itemId, 'subject_code' => 'STOCK_MIX', 'facts' => ['item_id' => $itemId, 'category_id' => (int) ($categories[$itemId] ?? 0),
                        'amount_base' => $figures['extra'], 'cost_difference_percent' => $figures['percent']]];
                }
            }
            if ($effect === VarianceEffect::ReasonRequired->value && $strongest === 'AUDIT_ONLY') {
                $strongest = 'REASON_REQUIRED';
            }
            $items[] = [
                'item_id' => $itemId, 'uom_id' => (int) $leaf['uom']['id'], 'standard_quantity' => (string) $standard, 'planned_quantity' => (string) $planned,
                'delta_quantity' => (string) $planned->minus($standard), 'delta_percent' => $v['delta_percent'] ?? null,
                'effect' => in_array($effect, ['AUDIT_ONLY', 'REASON_REQUIRED', 'APPROVAL_REQUIRED'], true) ? $effect : null,
                'reason_id' => $v['reason']['id'] ?? null, 'reason_note' => $v['note'] ?? null, 'policy_id' => $v['policy']['id'] ?? null, 'matched' => $v['matched'] ?? [],
                'routing_amount' => $amount,
            ] + $mixRow;
        }
        if ($approvals !== []) {
            $strongest = 'APPROVAL_REQUIRED';
        }
        usort($items, fn (array $a, array $b): int => $a['item_id'] <=> $b['item_id']);
        usort($approvals, fn (array $a, array $b): int => [$a['item_id'], $a['subject_code']] <=> [$b['item_id'], $b['subject_code']]);

        return new MaterialPlanEvaluation($items, $strongest, $this->fingerprint($version->id, $line, $items), $problems, $approvals);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function fingerprint(int $versionId, QuotationDraftLine $line, array $items): string
    {
        $canonical = ['v' => self::VERSION, 'version_id' => $versionId, 'work_quantity' => (string) $line->offered_quantity, 'work_uom_id' => (int) $line->offered_uom_id,
            'items' => array_map(fn (array $i): array => [
                'item_id' => $i['item_id'], 'standard' => $i['standard_quantity'], 'planned' => $i['planned_quantity'], 'effect' => $i['effect'], 'reason_id' => $i['reason_id'],
                'policy_id' => $i['policy_id'], 'routing_amount' => $i['routing_amount'], 'mix' => $i['mix'], 'mix_level' => $i['mix_level'], 'mix_status' => $i['mix_status'],
                'mix_percent' => $i['mix_percent'], 'mix_extra' => $i['mix_extra'],
            ], $items)];

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}

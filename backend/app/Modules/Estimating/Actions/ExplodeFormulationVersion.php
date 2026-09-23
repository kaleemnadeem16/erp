<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\FormulationLine;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\FormulationExplosion;
use App\Modules\Estimating\Support\FormulationLineBreakdown;
use App\Modules\Estimating\Support\IngredientCostResolver;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * docs/domain/formulations.md "Cost of one output unit", recursing through
 * any nested SEMI_FINISHED input that has its own ACTIVE formulation
 * (formulations.md "Nesting") rather than pricing it from stock — Phase 3
 * never posts to inventory, so a nested ingredient's cost is always the
 * theoretical recipe cost, never whatever AVCO value it happens to carry
 * from physical production (Phase 5's work_orders).
 *
 * Each formulation's cost_per_output_unit is computed independently from
 * its own recipe alone — never scaled by how much of it a parent needs.
 * A nested formulation is then just another priced ingredient: the parent
 * multiplies the quantity it needs by the child's cost_per_output_unit,
 * exactly like it would multiply by a leaf ingredient's AVCO unit cost.
 * (An earlier version of this action threaded a running "how much of the
 * top-level batch is this" multiplier through the recursion and divided by
 * each level's own *unscaled* output_qty — those two scalings don't
 * cancel out, and it produced a silently wrong number. Caught by
 * FormulationExplosionTest's two-level nesting case, not by inspection.)
 *
 * Implemented as depth-capped PHP recursion rather than the single
 * recursive-CTE query database.md's own aside suggests: this also builds
 * the per-level breakdown the "wastage compounding" landmine says the UI
 * must show, which a flat CTE result can't do in one pass, and at this
 * phase's usage pattern (a human previewing one formulation on demand, not
 * a hot path across many assemblies) the bounded extra queries this costs
 * are not a real concern. Reconsider if formulation costing ever runs at
 * bulk/hot-path scale.
 */
final class ExplodeFormulationVersion
{
    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly IngredientCostResolver $ingredientCost,
        private readonly UomConversionService $uomConverter,
    ) {}

    public function __invoke(FormulationVersion $version): FormulationExplosion
    {
        return $this->explode($version, 1);
    }

    private function explode(FormulationVersion $version, int $depth): FormulationExplosion
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DomainInvariantException(
                'Formulation nesting exceeds the maximum supported depth.',
                field: null,
            );
        }

        $version->loadMissing(['formulation', 'lines.inputItem', 'lines.uom']);

        $lines = [];
        $totalCost = BigDecimal::zero();

        foreach ($version->lines as $line) {
            $breakdown = $this->explodeLine($line, $depth);
            $lines[] = $breakdown;
            $totalCost = $totalCost->plus($breakdown->lineCost);
        }

        $costPerOutputUnit = $totalCost->dividedBy(
            BigDecimal::of($version->formulation->output_qty),
            4,
            RoundingMode::HALF_UP,
        );

        return new FormulationExplosion($version, $lines, $totalCost, $costPerOutputUnit);
    }

    private function explodeLine(FormulationLine $line, int $depth): FormulationLineBreakdown
    {
        $wastageMultiplier = BigDecimal::one()->plus(
            BigDecimal::of($line->wastage_pct)->dividedBy(100, 10, RoundingMode::HALF_UP)
        );
        $scaledQty = BigDecimal::of($line->qty)->multipliedBy($wastageMultiplier);

        $subFormulationVersion = $this->activeSubFormulationVersion($line->inputItem);

        if ($subFormulationVersion === null) {
            $qtyInStockUom = $this->uomConverter->convert($scaledQty, $line->uom, $line->inputItem->stockUom);
            $unitCost = ($this->ingredientCost)($line->inputItem);
            $lineCost = $qtyInStockUom->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);

            return new FormulationLineBreakdown($line, $unitCost, $lineCost, null);
        }

        $subExplosion = $this->explode($subFormulationVersion, $depth + 1);
        $unitCost = $subExplosion->costPerOutputUnit;
        $qtyInSubOutputUom = $this->uomConverter->convert($scaledQty, $line->uom, $subFormulationVersion->formulation->outputUom);
        $lineCost = $qtyInSubOutputUom->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);

        return new FormulationLineBreakdown($line, $unitCost, $lineCost, $subExplosion);
    }

    private function activeSubFormulationVersion(Item $item): ?FormulationVersion
    {
        if ($item->type !== ItemType::SemiFinished) {
            return null;
        }

        $formulation = Formulation::query()->where('output_item_id', $item->id)->first();

        if ($formulation === null) {
            return null;
        }

        return $formulation->activeVersion()->with(['formulation.outputUom', 'lines.inputItem.stockUom', 'lines.uom'])->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Estimating\Models\AssemblyComponent;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyComponentBreakdown;
use App\Modules\Estimating\Support\AssemblyComponentKind;
use App\Modules\Estimating\Support\AssemblyCostBreakdown;
use App\Modules\Estimating\Support\IngredientCostResolver;
use App\Modules\Estimating\Support\MarginType;
use App\Modules\Estimating\Support\OverheadAmountCalculator;
use App\Modules\Estimating\Support\OverheadCostBasis;
use App\Modules\Inventory\Models\Item;
use App\Support\UomConversionService;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * docs/domain/assemblies.md#cost-roll-up, wired to Step 1's Assembly/
 * AssemblyGroup and Step 2's overhead resolver. The four direct costs
 * (material/labour/plant/subcontract) are always computed first, from
 * components alone; overhead is resolved and computed strictly afterward,
 * from those four figures — never the other way around, so overhead can
 * never end up inside its own base (see AssemblyCostBreakdown's docblock).
 *
 * Overhead driver bases, defined explicitly since the docs never spell out
 * their composition in code terms:
 *   - PCT_MATERIAL / PCT_LABOUR — that one component-kind's direct cost
 *   - PCT_DIRECT_COST           — material + labour + plant + subcontract
 *     (all four stored kinds; subcontract is a direct cost of producing
 *     this unit of work, same as the other three, not overhead)
 *   - PER_LABOUR_HOUR           — total LABOUR qty_per_work_unit, converted
 *     to the company's base TIME-dimension UoM (by company convention this
 *     is hours; wastage/multiplier never apply to LABOUR, see explodeResource)
 *   - PER_WORK_UNIT             — exactly 1: every figure here is already
 *     "per unit" (docs/domain/assemblies.md's formulas are all /unit), so
 *     costing one work unit means the basis is 1, not the assembly's own
 *     work_uom quantity on some document that doesn't exist in Phase 3
 */
final class ExplodeAssemblyVersion
{
    public function __construct(
        private readonly IngredientCostResolver $ingredientCost,
        private readonly ResolveResourceRate $resolveResourceRate,
        private readonly ResolveOverheadPolicy $resolveOverheadPolicy,
        private readonly OverheadAmountCalculator $overheadCalculator,
        private readonly ExplodeFormulationVersion $explodeFormulation,
        private readonly UomConversionService $uomConverter,
    ) {}

    public function __invoke(AssemblyVersion $version): AssemblyCostBreakdown
    {
        $version->loadMissing([
            'assembly.workUom', 'assembly.assemblyGroup',
            'components.item.stockUom', 'components.formulation.outputUom',
            'components.applicationRate.item.stockUom', 'components.applicationRate.uom',
            'components.uom',
        ]);

        $componentBreakdowns = [];
        $materialCost = BigDecimal::zero();
        $labourCost = BigDecimal::zero();
        $labourHours = BigDecimal::zero();
        $plantCost = BigDecimal::zero();
        $subcontractCost = BigDecimal::zero();

        foreach ($version->components as $component) {
            $breakdown = match ($component->kind) {
                AssemblyComponentKind::Material => $this->explodeMaterial($component),
                AssemblyComponentKind::Labour, AssemblyComponentKind::Plant => $this->explodeResource($component),
                AssemblyComponentKind::Subcontract => $this->explodeSubcontract($component),
            };
            $componentBreakdowns[] = $breakdown;

            match ($component->kind) {
                AssemblyComponentKind::Material => $materialCost = $materialCost->plus($breakdown->lineCost),
                AssemblyComponentKind::Labour => $labourCost = $labourCost->plus($breakdown->lineCost),
                AssemblyComponentKind::Plant => $plantCost = $plantCost->plus($breakdown->lineCost),
                AssemblyComponentKind::Subcontract => $subcontractCost = $subcontractCost->plus($breakdown->lineCost),
            };

            if ($component->kind === AssemblyComponentKind::Labour) {
                $labourHours = $labourHours->plus($this->toBaseHours($breakdown->resolvedQtyPerWorkUnit, $component->uom));
            }
        }

        $directCost = $materialCost->plus($labourCost)->plus($plantCost)->plus($subcontractCost);

        $overheadResolution = ($this->resolveOverheadPolicy)($version->assembly);
        $overheadAmount = $overheadResolution->policy === null
            ? BigDecimal::zero()->toScale(4, RoundingMode::HALF_UP)
            : ($this->overheadCalculator)(
                $overheadResolution->policy->driver_type,
                $overheadResolution->policy->rate,
                new OverheadCostBasis(
                    materialCost: (string) $materialCost,
                    labourCost: (string) $labourCost,
                    plantCost: (string) $plantCost,
                    subcontractCost: (string) $subcontractCost,
                    labourHours: (string) $labourHours,
                    workQty: '1',
                ),
            );

        $costPerUnit = $directCost->plus($overheadAmount);
        $sellPerUnit = $this->applyMargin($version, $costPerUnit);

        return new AssemblyCostBreakdown(
            version: $version,
            components: $componentBreakdowns,
            materialCost: $materialCost,
            labourCost: $labourCost,
            labourHours: $labourHours,
            plantCost: $plantCost,
            subcontractCost: $subcontractCost,
            directCost: $directCost,
            overheadMethod: $overheadResolution->method,
            overheadPolicy: $overheadResolution->policy,
            overheadAmount: $overheadAmount,
            costPerUnit: $costPerUnit,
            sellPerUnit: $sellPerUnit,
        );
    }

    private function explodeMaterial(AssemblyComponent $component): AssemblyComponentBreakdown
    {
        if ($component->application_rate_id !== null) {
            $rate = $component->applicationRate;
            $qty = BigDecimal::of($rate->qty_per_work_unit);
            $qtyUom = $rate->uom;
            $item = $rate->item;
        } else {
            $qty = BigDecimal::of($component->qty_per_work_unit);
            $qtyUom = $component->uom;
            $item = $component->item;
        }

        $wastageMultiplier = BigDecimal::one()->plus(
            BigDecimal::of($component->wastage_pct)->dividedBy(100, 10, RoundingMode::HALF_UP)
        );
        $scaledQty = BigDecimal::of($qty)
            ->multipliedBy($wastageMultiplier)
            ->multipliedBy(BigDecimal::of($component->multiplier));

        if ($component->rate_override !== null) {
            // The override is $ per unit of the component's own qty_per_work_unit
            // UoM — no cross-UoM conversion is needed since there is no other
            // resolved cost source's UoM to convert against.
            $convertedQty = $scaledQty;
            $unitCost = BigDecimal::of($component->rate_override);
            $costSource = 'Manual override';
        } elseif ($component->formulation_id !== null) {
            $formulation = $component->formulation;
            $activeVersion = $formulation->activeVersion()
                ->with(['formulation.outputUom', 'lines.inputItem.stockUom', 'lines.uom'])
                ->first();

            if ($activeVersion === null) {
                throw new DomainInvariantException(
                    "Assembly component references formulation {$formulation->code}, which has no ACTIVE version.",
                    field: 'formulation_id',
                );
            }

            $explosion = ($this->explodeFormulation)($activeVersion);
            $unitCost = $explosion->costPerOutputUnit;
            $costUom = $formulation->outputUom;
            $convertedQty = $this->convert($scaledQty, $qtyUom, $costUom, $component);
            $costSource = "Formulation {$formulation->code} (v{$activeVersion->version_no})";
        } else {
            $unitCost = ($this->ingredientCost)($item);
            $costUom = $item->stockUom;
            $convertedQty = $this->convert($scaledQty, $qtyUom, $costUom, $component);
            $costSource = 'AVCO stock valuation';
        }

        $lineCost = $convertedQty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);

        return new AssemblyComponentBreakdown($component, $qty, $unitCost, $lineCost, $costSource);
    }

    /** LABOUR or PLANT — wastage/multiplier never apply, see docs/domain/assemblies.md#cost-roll-up. */
    private function explodeResource(AssemblyComponent $component): AssemblyComponentBreakdown
    {
        $qty = $component->qty_per_work_unit;

        if ($component->rate_override !== null) {
            $unitCost = BigDecimal::of($component->rate_override);
            $lineCost = BigDecimal::of($qty)->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);

            return new AssemblyComponentBreakdown($component, BigDecimal::of($qty), $unitCost, $lineCost, 'Manual override');
        }

        $item = $component->item;
        $resolved = ($this->resolveResourceRate)($item);

        if ($resolved === null) {
            // No resource rate configured yet for this item — a legitimate
            // early state, not an error, same as IngredientCostResolver's
            // "never received any stock" zero-cost fallback.
            return new AssemblyComponentBreakdown(
                $component, BigDecimal::of($qty), BigDecimal::zero(),
                BigDecimal::zero()->toScale(4, RoundingMode::HALF_UP),
                'No resource rate configured',
            );
        }

        $convertedQty = $this->convert(BigDecimal::of($qty), $component->uom, $resolved->rateUom, $component);
        $unitCost = BigDecimal::of($resolved->rate);
        $lineCost = $convertedQty->multipliedBy($unitCost)->toScale(4, RoundingMode::HALF_UP);

        return new AssemblyComponentBreakdown(
            $component, BigDecimal::of($qty), $unitCost, $lineCost,
            "Resource rate as of {$resolved->effective_from->toDateString()}",
        );
    }

    /** rate_override is required for SUBCONTRACT — see StoreAssemblyComponentRequest. */
    private function explodeSubcontract(AssemblyComponent $component): AssemblyComponentBreakdown
    {
        $unitCost = BigDecimal::of($component->rate_override);
        $lineCost = $unitCost->toScale(4, RoundingMode::HALF_UP);

        return new AssemblyComponentBreakdown($component, BigDecimal::one(), $unitCost, $lineCost, 'Direct rate');
    }

    private function convert(BigDecimal $qty, Uom $from, Uom $to, AssemblyComponent $component): BigDecimal
    {
        if ($from->dimension !== $to->dimension) {
            throw new DomainInvariantException(
                "Component #{$component->id} ({$component->kind->value}) uses [{$from->code}], which cannot ".
                "convert to [{$to->code}] — different dimensions ({$from->dimension->value} vs {$to->dimension->value}).",
                field: 'uom_id',
            );
        }

        return $this->uomConverter->convert($qty, $from, $to);
    }

    /**
     * PER_LABOUR_HOUR's basis: every LABOUR component's qty_per_work_unit,
     * normalised to the company's base TIME-dimension UoM — by convention
     * that base unit is hours (see FoundationDemoSeeder), but this reads
     * whichever UoM the company actually flagged is_base for TIME rather
     * than assuming a UoM code.
     */
    private function toBaseHours(BigDecimal $qty, Uom $componentUom): BigDecimal
    {
        $baseTimeUom = Uom::query()->where('dimension', UomDimension::Time)->where('is_base', true)->first();

        if ($baseTimeUom === null) {
            throw new DomainInvariantException(
                'No base unit is configured for the TIME dimension — PER_LABOUR_HOUR overhead cannot resolve an hours basis without one.',
                field: null,
            );
        }

        if ($componentUom->dimension !== UomDimension::Time) {
            throw new DomainInvariantException(
                "A LABOUR component's unit [{$componentUom->code}] is not a TIME-dimension unit; PER_LABOUR_HOUR needs an hours basis.",
                field: 'uom_id',
            );
        }

        return $this->uomConverter->convert($qty, $componentUom, $baseTimeUom);
    }

    private function applyMargin(AssemblyVersion $version, BigDecimal $cost): BigDecimal
    {
        $assembly = $version->assembly;
        $marginFraction = BigDecimal::of($assembly->margin_pct)->dividedBy(100, 10, RoundingMode::HALF_UP);

        if ($assembly->margin_type === MarginType::Markup) {
            return $cost->multipliedBy(BigDecimal::one()->plus($marginFraction))->toScale(4, RoundingMode::HALF_UP);
        }

        if ($marginFraction->isGreaterThanOrEqualTo(BigDecimal::one())) {
            throw new DomainInvariantException(
                "Assembly {$assembly->code}'s margin percentage must be below 100% to compute a sell price.",
                field: 'margin_pct',
            );
        }

        return $cost->dividedBy(BigDecimal::one()->minus($marginFraction), 4, RoundingMode::HALF_UP);
    }
}

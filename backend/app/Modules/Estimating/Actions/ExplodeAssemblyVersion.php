<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Models\AssemblyComponent;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Support\AssemblyComponentBreakdown;
use App\Modules\Estimating\Support\AssemblyComponentKind;
use App\Modules\Estimating\Support\AssemblyCostBreakdown;
use App\Modules\Estimating\Support\CostReadAuthorization;
use App\Modules\Estimating\Support\EstimateCostResolution;
use App\Modules\Estimating\Support\MarginType;
use App\Modules\Estimating\Support\OverheadAmountCalculator;
use App\Modules\Estimating\Support\OverheadCostBasis;
use App\Modules\Estimating\Support\OverheadDriverType;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Support\CompanyContext;
use App\Support\UomConversionService;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Supported Assembly arithmetic; current material demands share one read-only cost scenario. */
final class ExplodeAssemblyVersion
{
    public function __construct(
        private readonly QueryMaterialCost $materialCost,
        private readonly EstimateCostResolution $costs,
        private readonly CostReadAuthorization $authorise,
        private readonly ResolveResourceRate $resolveResourceRate,
        private readonly ResolveOverheadPolicy $resolveOverheadPolicy,
        private readonly OverheadAmountCalculator $overheadCalculator,
        private readonly ExplodeFormulationVersion $explodeFormulation,
        private readonly UomConversionService $uomConverter,
    ) {}

    public function __invoke(AssemblyVersion $version, User $actor): AssemblyCostBreakdown
    {
        ($this->authorise)($actor);

        return $this->materialCost->scenario(fn (CostReadSession $session): AssemblyCostBreakdown => $this->forWorkflow($version, $session));
    }

    /** Internal composition after the enclosing business Action authorises its caller. */
    public function forWorkflow(AssemblyVersion $version, CostReadSession $session, ?BigDecimal $workQuantity = null, ?CostResolution $contextMaterial = null): AssemblyCostBreakdown
    {
        if ($version->assembly->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The Assembly is outside the active company.');
        }
        $workQuantity ??= BigDecimal::one();
        $version->loadMissing(['assembly.workUom', 'assembly.assemblyGroup', 'components.item.stockUom',
            'components.formulation.outputUom', 'components.applicationRate.item.stockUom', 'components.applicationRate.uom', 'components.uom']);
        $components = [];
        $groups = [];
        $labourHours = BigDecimal::zero();
        foreach ($version->components->sortBy('id') as $component) {
            if ($contextMaterial !== null && $component->kind === AssemblyComponentKind::Material) {
                continue;
            }
            $row = match ($component->kind) {
                AssemblyComponentKind::Material => $this->explodeMaterial($component, $workQuantity, $session),
                AssemblyComponentKind::Labour, AssemblyComponentKind::Plant => $this->explodeResource($component, $workQuantity, $session),
                AssemblyComponentKind::Subcontract => $this->explodeSubcontract($component, $workQuantity, $session),
            };
            $components[] = $row;
            $groups[$component->kind->value][] = $row->cost;
            if ($component->kind === AssemblyComponentKind::Labour) {
                $labourHours = $labourHours->plus($this->toBaseHours($row->resolvedQuantity, $component->uom));
            }
        }
        if ($contextMaterial !== null) {
            $groups['MATERIAL'] = [$contextMaterial];
        }
        $costByKind = [];
        foreach (AssemblyComponentKind::cases() as $kind) {
            $costByKind[$kind->value] = $this->costs->aggregate($groups[$kind->value] ?? [], $workQuantity,
                $version->assembly->work_uom_id, 'WORK_QUANTITY', $session);
        }
        $directChildren = array_merge(...array_values($groups ?: [[]]));
        $direct = $this->costs->aggregate($directChildren, $workQuantity, $version->assembly->work_uom_id, 'WORK_QUANTITY', $session);
        $overhead = ($this->resolveOverheadPolicy)($version->assembly);
        $policy = $overhead->policy;
        $driverComplete = $policy === null || match ($policy->driver_type) {
            OverheadDriverType::PctMaterial => $costByKind['MATERIAL']->totalCost !== null,
            OverheadDriverType::PctLabour => $costByKind['LABOUR']->totalCost !== null,
            OverheadDriverType::PctDirectCost => $direct->totalCost !== null,
            default => true,
        };
        $overheadAmount = ! $driverComplete ? null : ($policy === null ? BigDecimal::zero() : ($this->overheadCalculator)(
            $policy->driver_type, $policy->rate, new OverheadCostBasis(
                materialCost: (string) ($costByKind['MATERIAL']->totalCost ?? BigDecimal::zero()),
                labourCost: (string) ($costByKind['LABOUR']->totalCost ?? BigDecimal::zero()),
                plantCost: (string) ($costByKind['PLANT']->totalCost ?? BigDecimal::zero()),
                subcontractCost: (string) ($costByKind['SUBCONTRACT']->totalCost ?? BigDecimal::zero()),
                labourHours: (string) $labourHours, workQty: (string) $workQuantity,
            )));
        $overheadCost = $this->costs->configured($overheadAmount, $workQuantity, $version->assembly->work_uom_id,
            'WORK_QUANTITY', $session, ['policy_id' => $policy?->id, 'rate' => $policy?->rate,
                'driver_type' => $policy?->driver_type->value], $driverComplete ? null : 'OVERHEAD_DRIVER_INCOMPLETE');
        $cost = $this->costs->aggregate($policy === null ? [$direct] : [$direct, $overheadCost], $workQuantity,
            $version->assembly->work_uom_id, 'WORK_QUANTITY', $session, ['source_type' => 'ASSEMBLY_VERSION', 'version_id' => $version->id]);
        $sell = $cost->unitCost === null ? null : $this->applyMargin($version, $cost->unitCost);

        return new AssemblyCostBreakdown($version, $components, $cost, $costByKind, $labourHours, $direct,
            $overhead->method, $policy, $overheadCost, $sell);
    }

    private function explodeMaterial(AssemblyComponent $component, BigDecimal $workQuantity, CostReadSession $session): AssemblyComponentBreakdown
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
            ->multipliedBy(BigDecimal::of($component->multiplier))->multipliedBy($workQuantity);

        if ($component->rate_override !== null) {
            // The override is $ per unit of the component's own qty_per_work_unit
            // UoM — no cross-UoM conversion is needed since there is no other
            // resolved cost source's UoM to convert against.
            $convertedQty = $scaledQty;
            $unitCost = BigDecimal::of($component->rate_override);
            $costSource = 'Manual override';
            $cost = $this->costs->configured($convertedQty->multipliedBy($unitCost), $convertedQty, $qtyUom->id, 'REQUIRED_QUANTITY', $session, ['component_id' => $component->id, 'rate_override' => $component->rate_override]);
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

            $costUom = $formulation->outputUom;
            $convertedQty = $this->convert($scaledQty, $qtyUom, $costUom, $component);
            $cost = $this->explodeFormulation->forWorkflow($activeVersion, $session, $convertedQty)->cost;
            $costSource = "Formulation {$formulation->code} (v{$activeVersion->version_no})";
        } else {
            $costUom = $item->stockUom;
            $convertedQty = $this->convert($scaledQty, $qtyUom, $costUom, $component);
            $cost = $this->materialCost->resolve(new MaterialCostRequest($item, $convertedQty), $session);
            $costSource = $item->valuation_method->value.' stock valuation';
        }

        return new AssemblyComponentBreakdown($component, $qty->multipliedBy($workQuantity), $cost, $costSource);
    }

    /** Resource quantities retain their supported UoM arithmetic; wastage/multipliers do not apply. */
    private function explodeResource(AssemblyComponent $component, BigDecimal $workQuantity, CostReadSession $session): AssemblyComponentBreakdown
    {
        $qty = BigDecimal::of($component->qty_per_work_unit)->multipliedBy($workQuantity);
        $rate = $component->rate_override === null ? ($this->resolveResourceRate)($component->item) : null;
        $unit = $component->rate_override === null ? ($rate === null ? null : BigDecimal::of($rate->rate)) : BigDecimal::of($component->rate_override);
        $converted = $rate === null ? $qty : $this->convert($qty, $component->uom, $rate->rateUom, $component);
        $source = $component->rate_override !== null ? 'Manual override' : ($rate === null ? 'No resource rate configured' : "Resource rate as of {$rate->effective_from->toDateString()}");
        $cost = $this->costs->configured($unit === null ? null : $converted->multipliedBy($unit), $converted,
            $rate->rate_uom_id ?? $component->uom_id, 'REQUIRED_QUANTITY', $session,
            ['component_id' => $component->id, 'rate_id' => $rate?->id, 'rate' => $unit === null ? null : (string) $unit,
                'effective_from' => $rate?->effective_from->toDateString()], $unit === null ? 'RESOURCE_RATE_MISSING' : null);

        return new AssemblyComponentBreakdown($component, $qty, $cost, $source);
    }

    private function explodeSubcontract(AssemblyComponent $component, BigDecimal $workQuantity, CostReadSession $session): AssemblyComponentBreakdown
    {
        $cost = $this->costs->configured(BigDecimal::of($component->rate_override)->multipliedBy($workQuantity),
            $workQuantity, $component->uom_id, 'WORK_QUANTITY', $session, ['component_id' => $component->id, 'rate_override' => $component->rate_override]);

        return new AssemblyComponentBreakdown($component, $workQuantity, $cost, 'Direct rate');
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

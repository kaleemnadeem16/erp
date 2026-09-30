<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyComponent;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\AssemblyComponentKind;
use App\Modules\Estimating\Support\IngredientCostResolver;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Estimating\Support\OverheadAmountCalculator;
use App\Modules\Estimating\Support\OverheadCostBasis;
use App\Modules\Estimating\Support\ResolutionMethod;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * ADR 0058's read-only preview: the technical resolution of an Assembly into
 * leaf material requirements and, for an actor allowed to see availability,
 * the advisory inventory proposal over them (PreviewInventoryAvailability).
 * Hypothetical planned quantities are judged by PreviewMaterialVariance and drive the inventory
 * proposal; nothing is ever adopted here (Phase 4).
 *
 * The application-rate id stored on an Assembly component is the configured
 * family anchor (item + denominator work UoM). The preview resolves the
 * winning row from that family using the supplied dimension context, so a
 * selected dimension is never a decorative input.
 */
final class PreviewAssemblyMaterials
{
    private const MAX_FORMULATION_DEPTH = 10;

    public function __construct(
        private readonly ResolveApplicationRate $resolveApplicationRate,
        private readonly ResolveFormulation $resolveFormulation,
        private readonly ExplodeAssemblyVersion $explodeAssembly,
        private readonly IngredientCostResolver $ingredientCost,
        private readonly OverheadAmountCalculator $overheadCalculator,
        private readonly UomConversionService $uomConverter,
        private readonly PreviewInventoryAvailability $inventoryPreview,
        private readonly PreviewMaterialVariance $variancePreview,
    ) {}

    /**
     * @param  array{assembly_version_id?: int|null, work_quantity: string, work_uom_id: int, customer_id?: int|null, dimension_value_ids?: list<int>, inventory_scope?: array{warehouse_ids?: list<int>, location_ids?: list<int>}, selection_strategy?: string|null, stock_mix?: list<array{item_id: int, location_id: int, batch_id?: int|null, quantity: string, uom_id: int}>, planned_adjustments?: list<array{item_id: int, planned_quantity: string, uom_id: int, reason_id?: int|null, note?: string|null}>}  $input
     * @return array<string, mixed>
     */
    public function __invoke(Assembly $assembly, array $input, bool $includeCost, bool $includeInventory = false): array
    {
        $assembly->loadMissing('workUom');
        $version = $this->resolveVersion($assembly, $input['assembly_version_id'] ?? null);
        $version->loadMissing([
            'components.item.stockUom',
            'components.formulation.outputUom',
            'components.applicationRate.item.stockUom',
            'components.applicationRate.workUom',
            'components.applicationRate.uom',
            'components.uom',
        ]);

        $requestedWorkUom = Uom::query()->findOrFail($input['work_uom_id']);
        $workQuantity = BigDecimal::of($input['work_quantity']);
        $assemblyWorkQuantity = $this->convert(
            $workQuantity,
            $requestedWorkUom,
            $assembly->workUom,
            'work_uom_id',
        );
        $customerId = $input['customer_id'] ?? null;
        $dimensionValueIds = $input['dimension_value_ids'] ?? [];

        /** @var array<int, array<string, mixed>> $leafRequirements */
        $leafRequirements = [];
        $materialComponents = [];
        $warnings = [];
        $materialCost = BigDecimal::zero();

        foreach ($version->components->where('kind', AssemblyComponentKind::Material) as $component) {
            $resolved = $this->resolveMaterialComponent(
                $component,
                $workQuantity,
                $requestedWorkUom,
                $assemblyWorkQuantity,
                $customerId,
                $dimensionValueIds,
                $leafRequirements,
                $warnings,
                $includeCost,
            );
            $materialComponents[] = $resolved['component'];
            $materialCost = $materialCost->plus($resolved['cost']);
        }

        $leafRows = array_values(array_map(function (array $row): array {
            $row['standard_quantity'] = (string) $row['standard_quantity'];
            $row['contributions'] = array_map(function (array $contribution): array {
                $contribution['quantity'] = (string) $contribution['quantity'];

                return $contribution;
            }, $row['contributions']);

            return $row;
        }, $leafRequirements));

        usort($leafRows, fn (array $a, array $b): int => strcmp($a['item']['code'], $b['item']['code']));

        $costBreakdown = $includeCost
            ? $this->costBreakdown($version, $assemblyWorkQuantity, $materialCost)
            : null;

        // Initialised first, then destructured: Scramble types the response from this flow, and a
        // destructuring that is the variable's first assignment reads as `string` in the OpenAPI schema.
        $variance = null;
        $plannedByItem = [];
        $varianceEvidence = [];
        [$variance, $plannedByItem, $varianceEvidence] = ($this->variancePreview)(
            $leafRows,
            $input['planned_adjustments'] ?? [],
            $includeCost,
        );

        $inventory = null;
        $inventoryEvidence = null;
        if ($includeInventory) {
            ['data' => $inventory, 'evidence' => $inventoryEvidence] = ($this->inventoryPreview)(
                $leafRows,
                $input['inventory_scope'] ?? [],
                isset($input['selection_strategy']) ? MaterialSelectionStrategy::from($input['selection_strategy']) : null,
                $includeCost,
                $plannedByItem,
                $input['stock_mix'] ?? [],
            );
        }

        $evidence = [
            'assembly_id' => $assembly->id,
            'version_id' => $version->id,
            'work_quantity' => (string) $workQuantity,
            'work_uom_id' => $requestedWorkUom->id,
            'customer_id' => $customerId,
            'dimension_value_ids' => $dimensionValueIds,
            'materials' => array_map(fn (array $row): array => [
                'item_id' => $row['item']['id'],
                'quantity' => $row['standard_quantity'],
                'uom_id' => $row['uom']['id'],
            ], $leafRows),
        ];
        if ($varianceEvidence !== [] && ($input['planned_adjustments'] ?? []) !== []) {
            $evidence['variance'] = $varianceEvidence;
        }
        // Availability moves the fingerprint only for an actor who can see it; otherwise a
        // changing fingerprint would leak stock movements to someone who may not see stock.
        if ($inventoryEvidence !== null) {
            $evidence['inventory'] = $inventoryEvidence;
        }

        return [
            'calculated_at' => now()->toIso8601String(),
            'context' => [
                'assembly' => ['id' => $assembly->id, 'code' => $assembly->code, 'name' => $assembly->name],
                'assembly_version' => ['id' => $version->id, 'version_no' => $version->version_no, 'status' => $version->status->value],
                'requested_work_quantity' => (string) $workQuantity,
                'requested_work_uom' => $this->uomData($requestedWorkUom),
                'resolved_work_quantity' => (string) $assemblyWorkQuantity,
                'assembly_work_uom' => $this->uomData($assembly->workUom),
                'customer_id' => $customerId,
                'dimension_value_ids' => $dimensionValueIds,
            ],
            'material_components' => $materialComponents,
            'leaf_requirements' => $leafRows,
            'cost_breakdown' => $costBreakdown,
            // No adjustments asked for → no block at all, rather than an empty one.
            'variance' => ($input['planned_adjustments'] ?? []) === [] ? null : $variance,
            'inventory' => $inventory,
            'warnings' => array_values(array_unique($warnings)),
            'limitations' => [
                'Package-level proposals are not included yet, and a planned quantity is only ever a what-if: it is not adopted or saved here.',
                'Advisory only: this calculation does not reserve, allocate or post inventory.',
            ],
            'evidence_fingerprint' => hash('sha256', (string) json_encode($evidence, JSON_THROW_ON_ERROR)),
        ];
    }

    private function resolveVersion(Assembly $assembly, ?int $versionId): AssemblyVersion
    {
        $version = $versionId === null
            ? $assembly->activeVersion()->first()
            : $assembly->versions()->whereKey($versionId)->first();

        if ($version === null) {
            throw new DomainInvariantException(
                $versionId === null
                    ? "Assembly {$assembly->code} has no ACTIVE version to preview."
                    : 'The selected Assembly version does not belong to this Assembly.',
                field: 'assembly_version_id',
            );
        }

        return $version;
    }

    /**
     * @param  list<int>  $dimensionValueIds
     * @param  array<int, array<string, mixed>>  $leafRequirements
     * @param  list<string>  $warnings
     * @return array{component: array<string, mixed>, cost: BigDecimal}
     */
    private function resolveMaterialComponent(
        AssemblyComponent $component,
        BigDecimal $requestedWorkQuantity,
        Uom $requestedWorkUom,
        BigDecimal $assemblyWorkQuantity,
        ?int $customerId,
        array $dimensionValueIds,
        array &$leafRequirements,
        array &$warnings,
        bool $includeCost,
    ): array {
        $rateData = null;

        if ($component->application_rate_id !== null) {
            $anchor = $component->applicationRate;
            $resolution = ($this->resolveApplicationRate)($anchor->item_id, $anchor->work_uom_id, $dimensionValueIds);
            $rate = $resolution->rate->loadMissing(['item.stockUom', 'workUom', 'uom', 'dimensionValues']);
            $workInRateUom = $this->convert($requestedWorkQuantity, $requestedWorkUom, $rate->workUom, 'work_uom_id');
            $quantity = BigDecimal::of($rate->qty_per_work_unit)->multipliedBy($workInRateUom);
            $quantityUom = $rate->uom;
            $item = $rate->item;
            $rateData = [
                'anchor_id' => $anchor->id,
                'resolved_id' => $rate->id,
                'method' => $resolution->method->value,
                'qty_per_work_unit' => (string) $rate->qty_per_work_unit,
                'quantity_uom' => $this->uomData($rate->uom),
                'work_uom' => $this->uomData($rate->workUom),
                'dimension_value_ids' => $rate->dimensionValues->pluck('id')->sort()->values()->all(),
            ];

            if ($resolution->method === ResolutionMethod::Default) {
                $warnings[] = "Component #{$component->id} used the default application rate because no dimension-specific rate matched.";
            } elseif ($resolution->method === ResolutionMethod::RequiredOnlyMatch) {
                $warnings[] = "Component #{$component->id} matched required rate dimensions only; optional dimensions were not decisive.";
            }
        } else {
            $quantity = BigDecimal::of($component->qty_per_work_unit)->multipliedBy($assemblyWorkQuantity);
            $quantityUom = $component->uom;
            $item = $component->item;
        }

        if ($item === null) {
            throw new DomainInvariantException("Material component #{$component->id} has no resolvable item.", 'item_id');
        }

        $standardQuantity = $quantity
            ->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($component->wastage_pct)->dividedBy(100, 10, RoundingMode::HALF_UP)))
            ->multipliedBy(BigDecimal::of($component->multiplier));
        $path = ["Assembly component #{$component->id}: {$item->code}"];
        $formulationData = null;
        $componentLeaves = [];

        if ($component->formulation_id !== null) {
            $formulation = ($this->resolveFormulation)($item->id, $customerId)->loadMissing(['outputUom', 'activeVersion']);
            $activeVersion = $formulation->activeVersion;
            if ($activeVersion === null) {
                throw new DomainInvariantException("Formulation {$formulation->code} has no ACTIVE version.", 'formulation_id');
            }

            $outputQuantity = $this->convert($standardQuantity, $quantityUom, $formulation->outputUom, 'formulation_id');
            $formulationData = $this->formulationData($formulation, $activeVersion);
            $componentLeaves = $this->expandFormulation(
                $activeVersion,
                $outputQuantity,
                $customerId,
                [...$path, "Formulation {$formulation->code} v{$activeVersion->version_no}"],
                1,
            );
        } else {
            $stockQuantity = $this->convert($standardQuantity, $quantityUom, $item->stockUom, 'uom_id');
            $componentLeaves[] = ['item' => $item, 'quantity' => $stockQuantity, 'path' => $path];
        }

        foreach ($componentLeaves as $leaf) {
            $this->addLeafRequirement($leafRequirements, $leaf['item'], $leaf['quantity'], $leaf['path'], $component->id);
        }

        $cost = BigDecimal::zero();
        if ($includeCost) {
            if ($component->rate_override !== null) {
                $cost = $standardQuantity->multipliedBy(BigDecimal::of($component->rate_override));
            } else {
                foreach ($componentLeaves as $leaf) {
                    $cost = $cost->plus($leaf['quantity']->multipliedBy(($this->ingredientCost)($leaf['item'])));
                }
            }
            $cost = $cost->toScale(4, RoundingMode::HALF_UP);
        }

        return [
            'component' => [
                'component_id' => $component->id,
                'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name],
                'standard_quantity' => (string) $standardQuantity,
                'uom' => $this->uomData($quantityUom),
                'wastage_pct' => (string) $component->wastage_pct,
                'multiplier' => (string) $component->multiplier,
                'application_rate' => $rateData,
                'formulation' => $formulationData,
            ],
            'cost' => $cost,
        ];
    }

    /**
     * @param  list<string>  $path
     * @return list<array{item: Item, quantity: BigDecimal, path: list<string>}>
     */
    private function expandFormulation(
        FormulationVersion $version,
        BigDecimal $requiredOutputQuantity,
        ?int $customerId,
        array $path,
        int $depth,
    ): array {
        if ($depth > self::MAX_FORMULATION_DEPTH) {
            throw new DomainInvariantException('Formulation nesting exceeds the maximum supported depth.');
        }

        $version->loadMissing(['formulation.outputUom', 'lines.inputItem.stockUom', 'lines.uom']);
        $batchFactor = $requiredOutputQuantity->dividedBy(
            BigDecimal::of($version->formulation->output_qty),
            12,
            RoundingMode::HALF_UP,
        );
        $leaves = [];

        foreach ($version->lines as $line) {
            $lineQuantity = BigDecimal::of($line->qty)
                ->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($line->wastage_pct)->dividedBy(100, 10, RoundingMode::HALF_UP)))
                ->multipliedBy($batchFactor);
            $linePath = [...$path, "Ingredient {$line->inputItem->code}"];
            $nested = $this->optionalFormulation($line->inputItem, $customerId);

            if ($nested === null) {
                $stockQuantity = $this->convert($lineQuantity, $line->uom, $line->inputItem->stockUom, 'uom_id');
                $leaves[] = ['item' => $line->inputItem, 'quantity' => $stockQuantity, 'path' => $linePath];

                continue;
            }

            $nestedVersion = $nested->activeVersion()->first();
            if ($nestedVersion === null) {
                throw new DomainInvariantException("Formulation {$nested->code} has no ACTIVE version.", 'formulation_id');
            }
            $nestedOutputQuantity = $this->convert($lineQuantity, $line->uom, $nested->outputUom, 'uom_id');
            array_push(
                $leaves,
                ...$this->expandFormulation(
                    $nestedVersion,
                    $nestedOutputQuantity,
                    $customerId,
                    [...$linePath, "Formulation {$nested->code} v{$nestedVersion->version_no}"],
                    $depth + 1,
                ),
            );
        }

        return $leaves;
    }

    private function optionalFormulation(Item $item, ?int $customerId): ?Formulation
    {
        if ($item->type !== ItemType::SemiFinished) {
            return null;
        }

        try {
            return ($this->resolveFormulation)($item->id, $customerId)->loadMissing('outputUom');
        } catch (DomainInvariantException) {
            return null;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @param  list<string>  $path
     */
    private function addLeafRequirement(array &$requirements, Item $item, BigDecimal $quantity, array $path, int $componentId): void
    {
        if (! isset($requirements[$item->id])) {
            $requirements[$item->id] = [
                'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name],
                'standard_quantity' => BigDecimal::zero(),
                'uom' => $this->uomData($item->stockUom),
                'contributions' => [],
            ];
        }

        $requirements[$item->id]['standard_quantity'] = $requirements[$item->id]['standard_quantity']->plus($quantity);
        $requirements[$item->id]['contributions'][] = [
            'component_id' => $componentId,
            'quantity' => $quantity,
            'path' => $path,
        ];
    }

    /** @return array<string, mixed> */
    private function costBreakdown(AssemblyVersion $version, BigDecimal $workQuantity, BigDecimal $materialCost): array
    {
        $perUnit = ($this->explodeAssembly)($version);
        $labourCost = $perUnit->labourCost->multipliedBy($workQuantity)->toScale(4, RoundingMode::HALF_UP);
        $labourHours = $perUnit->labourHours->multipliedBy($workQuantity);
        $plantCost = $perUnit->plantCost->multipliedBy($workQuantity)->toScale(4, RoundingMode::HALF_UP);
        $subcontractCost = $perUnit->subcontractCost->multipliedBy($workQuantity)->toScale(4, RoundingMode::HALF_UP);
        $directCost = $materialCost->plus($labourCost)->plus($plantCost)->plus($subcontractCost);
        $overheadAmount = $perUnit->overheadPolicy === null
            ? BigDecimal::zero()->toScale(4, RoundingMode::HALF_UP)
            : ($this->overheadCalculator)(
                $perUnit->overheadPolicy->driver_type,
                $perUnit->overheadPolicy->rate,
                new OverheadCostBasis(
                    materialCost: (string) $materialCost,
                    labourCost: (string) $labourCost,
                    plantCost: (string) $plantCost,
                    subcontractCost: (string) $subcontractCost,
                    labourHours: (string) $labourHours,
                    workQty: (string) $workQuantity,
                ),
            );

        return [
            'material_cost' => (string) $materialCost,
            'labour_cost' => (string) $labourCost,
            'labour_hours' => (string) $labourHours,
            'plant_cost' => (string) $plantCost,
            'subcontract_cost' => (string) $subcontractCost,
            'direct_cost' => (string) $directCost,
            'overhead' => [
                'method' => $perUnit->overheadMethod->value,
                'policy_id' => $perUnit->overheadPolicy?->id,
                'driver_type' => $perUnit->overheadPolicy?->driver_type->value,
                'rate' => $perUnit->overheadPolicy?->rate,
                'amount' => (string) $overheadAmount,
            ],
            'total_cost' => (string) $directCost->plus($overheadAmount),
            'non_material_components' => array_values(array_map(
                fn ($row): array => [
                    'component_id' => $row->component->id,
                    'kind' => $row->component->kind->value,
                    'resolved_quantity' => (string) $row->resolvedQtyPerWorkUnit->multipliedBy($workQuantity),
                    'uom' => $this->uomData($row->component->uom),
                    'line_cost' => (string) $row->lineCost->multipliedBy($workQuantity)->toScale(4, RoundingMode::HALF_UP),
                    'cost_source' => $row->costSource,
                ],
                array_filter($perUnit->components, fn ($row): bool => $row->component->kind !== AssemblyComponentKind::Material),
            )),
        ];
    }

    private function convert(BigDecimal $quantity, Uom $from, Uom $to, string $field): BigDecimal
    {
        try {
            return $this->uomConverter->convert($quantity, $from, $to);
        } catch (InvalidArgumentException $exception) {
            throw new DomainInvariantException($exception->getMessage(), $field);
        }
    }

    /** @return array{id: int, code: string, name: string} */
    private function uomData(Uom $uom): array
    {
        return ['id' => $uom->id, 'code' => $uom->code, 'name' => $uom->name];
    }

    /** @return array{id: int, code: string, version_id: int, version_no: int, customer_id: int|null} */
    private function formulationData(Formulation $formulation, FormulationVersion $version): array
    {
        return [
            'id' => $formulation->id,
            'code' => $formulation->code,
            'version_id' => $version->id,
            'version_no' => $version->version_no,
            'customer_id' => $formulation->customer_id,
        ];
    }
}

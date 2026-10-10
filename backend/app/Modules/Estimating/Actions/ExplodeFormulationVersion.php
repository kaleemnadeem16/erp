<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\FormulationVersion;
use App\Modules\Estimating\Support\CostReadAuthorization;
use App\Modules\Estimating\Support\EstimateCostResolution;
use App\Modules\Estimating\Support\FormulationExplosion;
use App\Modules\Estimating\Support\FormulationLineBreakdown;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Modules\Inventory\Support\ItemType;
use App\Support\CompanyContext;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Theoretical recipe cost, with one shared scenario for all actual leaf demands. */
final class ExplodeFormulationVersion
{
    private const MAX_DEPTH = 10;

    public function __construct(
        private readonly QueryMaterialCost $materialCost,
        private readonly UomConversionService $uomConverter,
        private readonly EstimateCostResolution $costs,
        private readonly CostReadAuthorization $authorise,
    ) {}

    public function __invoke(FormulationVersion $version, User $actor): FormulationExplosion
    {
        ($this->authorise)($actor);

        return $this->materialCost->scenario(fn (CostReadSession $session): FormulationExplosion => $this->forWorkflow($version, $session));
    }

    /** Internal composition by a business Action that has already authorised its caller. */
    public function forWorkflow(FormulationVersion $version, CostReadSession $session, ?BigDecimal $requiredOutput = null, int $depth = 1): FormulationExplosion
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DomainInvariantException('Formulation nesting exceeds the maximum supported depth.');
        }
        if ($version->formulation->company_id !== app(CompanyContext::class)->id()) {
            throw new DomainInvariantException('The formulation is outside the active company.');
        }
        $version->loadMissing(['formulation.outputUom', 'lines.inputItem.stockUom', 'lines.uom']);
        $output = $requiredOutput ?? BigDecimal::of($version->formulation->output_qty);
        $factor = $output->dividedBy($version->formulation->output_qty, 12, RoundingMode::HALF_UP);
        $lines = [];
        $children = [];
        foreach ($version->lines->sortBy('sequence') as $line) {
            $quantity = BigDecimal::of($line->qty)
                ->multipliedBy(BigDecimal::one()->plus(BigDecimal::of($line->wastage_pct)->dividedBy(100, 10, RoundingMode::HALF_UP)))
                ->multipliedBy($factor);
            $nested = $this->activeSubFormulationVersion($line->inputItem);
            $sub = null;
            if ($nested === null) {
                $stock = $this->uomConverter->convert($quantity, $line->uom, $line->inputItem->stockUom);
                $cost = $this->materialCost->resolve(new MaterialCostRequest($line->inputItem, $stock), $session);
            } else {
                $needed = $this->uomConverter->convert($quantity, $line->uom, $nested->formulation->outputUom);
                $sub = $this->forWorkflow($nested, $session, $needed, $depth + 1);
                $cost = $sub->cost;
            }
            $lines[] = new FormulationLineBreakdown($line, $cost, $sub);
            $children[] = $cost;
        }

        return new FormulationExplosion($version, $lines, $this->costs->aggregate($children, $output,
            $version->formulation->output_uom_id, 'OUTPUT_BATCH', $session,
            ['source_type' => 'FORMULATION_VERSION', 'version_id' => $version->id, 'formulation_id' => $version->formulation_id,
                'output_qty' => $version->formulation->output_qty]));
    }

    private function activeSubFormulationVersion(Item $item): ?FormulationVersion
    {
        if ($item->type !== ItemType::SemiFinished) {
            return null;
        }
        $formulation = Formulation::query()->where('output_item_id', $item->id)->first();

        return $formulation?->activeVersion()->with('formulation.outputUom')->first();
    }
}

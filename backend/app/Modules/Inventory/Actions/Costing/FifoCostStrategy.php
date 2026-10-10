<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Costing;

use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostResolutionFactory;
use App\Modules\Inventory\Support\Costing\CostStrategy;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use Brick\Math\BigDecimal;

final class FifoCostStrategy implements CostStrategy
{
    public function __construct(private readonly ReadEligibleCostLayers $layers, private readonly CostResolutionFactory $results) {}

    public function method(): string
    {
        return 'FIFO';
    }

    public function resolve(MaterialCostRequest $request, CostReadSession $session): CostResolution
    {
        $remaining = $request->quantity;
        $known = null;
        $basis = [];
        foreach (($this->layers)($request) as $eligible) {
            if ($remaining->isZero()) {
                break;
            }
            $layer = $eligible['layer'];
            $available = $session->remaining($layer->id, $eligible['quantity']);
            if (! $available->isPositive() || BigDecimal::of($layer->base_unit_cost)->isNegative()) {
                continue;
            }
            $take = $available->isLessThan($remaining) ? $available : $remaining;
            $session->attribute($layer->id, $take);
            $amount = $session->attributeCost($layer->id, $take->multipliedBy($layer->base_unit_cost));
            $known = ($known ?? BigDecimal::zero())->plus($amount);
            $remaining = $remaining->minus($take);
            $basis[] = ['source_type' => 'STOCK_LAYER', 'layer_id' => $layer->id, 'location_id' => $layer->location_id,
                'batch_id' => $layer->batch_id, 'serial_no' => $layer->serial_no, 'quantity' => (string) $take,
                'quantity_available' => (string) $eligible['quantity'], 'base_unit_cost' => $layer->base_unit_cost,
                'unit_cost' => $layer->unit_cost, 'currency_id' => $layer->currency_id, 'fx_rate' => $layer->fx_rate,
                'received_at' => $layer->received_at->toIso8601String(), 'source_document_type' => $layer->source_type,
                'source_id' => $layer->source_id, 'source_line_no' => $layer->source_line_no];
        }

        return $this->results->make($request, $session, $request->quantity->minus($remaining), $known, $basis,
            $remaining->isZero() ? null : ($known === null ? 'NO_COST_EVIDENCE' : 'INSUFFICIENT_COST_COVERAGE'));
    }
}

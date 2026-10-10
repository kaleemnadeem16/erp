<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Support\EstimateCostResolution;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostStatus;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;

/**
 * Prices stock draws through Inventory's read-only cost query (ADR 0077/0110). A draw is the actual quantity taken
 * from one location/batch balance, so its cost is a SELECTED_DRAW amount: it can be complete (COSTED) while the
 * requirement it serves is still short, and it is never a cost of the whole requirement.
 *
 * Two uses, kept apart: ranking (one balance, fresh scenario, no consumption of any other) and pricing a plan
 * (every line of one plan in one shared scenario, so a layer is never used twice within that plan). Alternatives and
 * a hand-built mix are separate hypothetical scenarios and take their own sessions. Nothing is reserved or posted.
 */
final class PriceStockDraw
{
    public const BASIS = 'SELECTED_DRAW';

    public function __construct(private readonly QueryMaterialCost $cost, private readonly EstimateCostResolution $costs) {}

    /** A scenario of its own on the same read snapshot. */
    public function scenario(CostReadSession $snapshot): CostReadSession
    {
        return new CostReadSession($snapshot->observedAt);
    }

    /**
     * The balance carrying the unit cost of the first draw it would supply (its usable stock, up to the requirement).
     * Anything short of complete evidence leaves the cost null, which ranks after every costed balance — unknown is
     * never cheapest — while a genuine zero is a real, ranking cost.
     */
    public function ranked(Item $item, StockBalance $balance, BigDecimal $required, CostReadSession $snapshot): StockBalance
    {
        $quantity = $balance->usable->isLessThan($required) ? $balance->usable : $required;
        if (! $quantity->isPositive()) {
            return $balance;
        }
        $resolution = $this->resolve($item, $balance, $quantity, $this->scenario($snapshot));

        return $resolution->status === CostStatus::Costed
            ? $balance->withCostEvidence($resolution->unitCost, $resolution->method)
            : $balance;
    }

    public function resolve(Item $item, StockBalance $balance, BigDecimal $quantity, CostReadSession $session): CostResolution
    {
        return $this->cost->resolve(new MaterialCostRequest($item, $quantity, $balance->warehouseId, $balance->locationId,
            $balance->batchId, amountBasis: self::BASIS), $session);
    }

    /**
     * @param  list<CostResolution>  $lines
     */
    public function total(Item $item, array $lines, BigDecimal $covered, CostReadSession $session): ?CostResolution
    {
        return $lines === [] ? null : $this->costs->aggregate($lines, $covered, $item->stock_uom_id, self::BASIS, $session);
    }
}

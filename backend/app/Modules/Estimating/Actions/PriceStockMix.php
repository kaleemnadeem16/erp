<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Estimating\Support\StockMix;
use App\Modules\Estimating\Support\StockMixLine;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Validates and prices a hand-built stock mix for one item (ADR 0072). The mix is the user's choice of quantity per
 * location/batch; this action decides whether it is a valid plan and what it costs, from the same balances and cost
 * evidence an automatic proposal uses — it never trusts a client figure.
 *
 * A mix is refused, not warned about, when any line:
 *  - names stock that is not a usable balance in this preview's scope (absent, held for QA, in a location that does
 *    not count, or nothing left), or draws more than that balance's usable quantity;
 *  - repeats a location/batch already listed;
 *  - is not a multiple of the item's quantity precision, or its unit does not convert to the item's stock unit.
 * And when the lines add up to more than the requirement: drawing more than the plan needs is a planned-quantity
 * change, judged by the variance rules, so a mix can never be a side door around them. Adding up to less is allowed
 * and reported as a shortage, exactly as an automatic proposal reports one.
 *
 * Quantities are entered in any unit that converts to the item's stock unit (a drum, a box, a metre of a roll) and
 * are held in the stock unit; a fraction of a pack is just a smaller stock quantity. Sealed/opened package state is
 * not tracked in QUANTITY_ONLY, so no claim about it is made.
 */
final class PriceStockMix
{
    public function __construct(private readonly ItemUomConversionService $conversion, private readonly PriceStockDraw $draws) {}

    /**
     * @param  list<StockBalance>  $balances  every balance in the preview's scope for the item, usable or not
     * @param  list<array{index: int, location_id: int, batch_id: int|null, quantity: string, uom_id: int}>  $lines
     */
    public function __invoke(Item $item, BigDecimal $required, array $balances, array $lines, CostReadSession $snapshot): StockMix
    {
        // A hand-built mix is its own hypothetical scenario: it does not consume what a proposal would.
        $session = $this->draws->scenario($snapshot);
        $byKey = [];
        foreach ($balances as $balance) {
            $byKey[$balance->locationId.':'.($balance->batchId ?? 0)] = $balance;
        }

        $priced = [];
        $seen = [];
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $index = $line['index'];
            $key = $line['location_id'].':'.($line['batch_id'] ?? 0);
            if (isset($seen[$key])) {
                throw new DomainInvariantException("{$item->code} is listed twice for the same location and batch; combine the two into one line.", "stock_mix.{$index}.location_id");
            }
            $seen[$key] = true;

            $uom = Uom::query()->findOrFail($line['uom_id']);
            $factor = $this->factor($item, $uom, $index);
            $stockQuantity = BigDecimal::of($line['quantity'])->multipliedBy($factor)->toScale(9, RoundingMode::HALF_UP);
            $this->assertPrecision($item, $stockQuantity, $index);

            $balance = $byKey[$key] ?? null;
            $usable = $this->assertUsable($item, $balance, $stockQuantity, $index);

            $cost = $this->draws->resolve($item, $balance, $stockQuantity, $session);
            $priced[] = new StockMixLine(
                $balance,
                $stockQuantity,
                $cost->totalCost,
                $line['quantity'],
                $uom,
                $factor,
                $usable->minus($stockQuantity),
                $cost,
            );
            $total = $total->plus($stockQuantity);
        }

        if ($total->isGreaterThan($required)) {
            $over = $total->minus($required)->stripTrailingZeros();
            throw new DomainInvariantException(
                "The stock chosen for {$item->code} adds up to {$total->stripTrailingZeros()} {$item->stockUom->code}, {$over} {$item->stockUom->code} more than the plan needs. To use more than the plan, change the planned quantity so the variance rules apply.",
                'stock_mix.'.$lines[0]['index'].'.quantity',
            );
        }

        $drawCost = $this->draws->total($item, array_map(fn (StockMixLine $line): CostResolution => $line->cost, $priced), $total, $session);

        return new StockMix(
            $priced,
            $total->toScale(4, RoundingMode::HALF_UP),
            $required->minus($total)->toScale(4, RoundingMode::HALF_UP),
            $drawCost?->totalCost,
            $drawCost,
        );
    }

    private function factor(Item $item, Uom $uom, int $index): BigDecimal
    {
        try {
            return $this->conversion->stockUnitFactor($item, $uom);
        } catch (InvalidArgumentException) {
            throw new DomainInvariantException("{$uom->code} does not convert to {$item->code}'s stock unit ({$item->stockUom->code}).", "stock_mix.{$index}.uom_id");
        }
    }

    private function assertPrecision(Item $item, BigDecimal $stockQuantity, int $index): void
    {
        if ($item->quantity_precision === null) {
            return;
        }
        $precision = BigDecimal::of($item->quantity_precision);
        if (! $stockQuantity->remainder($precision)->isZero()) {
            throw new DomainInvariantException("{$item->code} can only be drawn in multiples of {$precision->stripTrailingZeros()} {$item->stockUom->code}.", "stock_mix.{$index}.quantity");
        }
    }

    /** @return BigDecimal the balance's usable quantity before this draw */
    private function assertUsable(Item $item, ?StockBalance $balance, BigDecimal $stockQuantity, int $index): BigDecimal
    {
        $unit = $item->stockUom->code;
        if ($balance === null) {
            throw new DomainInvariantException("No stock of {$item->code} is held at that location and batch within this preview's stock scope.", "stock_mix.{$index}.location_id");
        }
        if ($balance->usable->isLessThanOrEqualTo(0)) {
            $why = match (true) {
                ! $balance->locationUsable && $balance->held->isLessThanOrEqualTo(0) => 'its location does not count as usable stock',
                $balance->held->isGreaterThan(0) && $balance->unusableLocation->isLessThanOrEqualTo(0) => "{$balance->held->stripTrailingZeros()} {$unit} of it is held for QA release",
                default => 'nothing usable is left there',
            };
            throw new DomainInvariantException("That stock of {$item->code} cannot be drawn: {$why}.", "stock_mix.{$index}.location_id");
        }
        if ($stockQuantity->isGreaterThan($balance->usable)) {
            throw new DomainInvariantException(
                "Only {$balance->usable->stripTrailingZeros()} {$unit} of {$item->code} is usable there; {$stockQuantity->stripTrailingZeros()} {$unit} was chosen.",
                "stock_mix.{$index}.quantity",
            );
        }

        return $balance->usable;
    }
}

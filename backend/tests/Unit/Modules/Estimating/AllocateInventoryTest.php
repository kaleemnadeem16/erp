<?php

namespace Tests\Unit\Modules\Estimating;

use App\Modules\Estimating\Actions\AllocateInventory;
use App\Modules\Estimating\Support\InventoryAllocation;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;
use Tests\TestCase;

/**
 * AllocateInventory is a pure function of a requirement, usable balances and a
 * preset, so every case here is hand-checkable: a balance is "location id,
 * usable quantity, unit cost" and the expected draw is written out in full.
 */
class AllocateInventoryTest extends TestCase
{
    private AllocateInventory $allocate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocate = new AllocateInventory;
    }

    public function test_cost_first_presets_fill_the_cheapest_balance_first(): void
    {
        $balances = [$this->balance(1, '10', '5.0000'), $this->balance(2, '10', '3.0000'), $this->balance(3, '10', '4.0000')];

        foreach ([MaterialSelectionStrategy::MinimiseWaste, MaterialSelectionStrategy::LowestInternalCost] as $strategy) {
            $plan = ($this->allocate)(BigDecimal::of('15'), $balances, $strategy);

            $this->assertSame([[2, '10'], [3, '5']], $this->draw($plan), $strategy->value);
            $this->assertSame('0', $this->plain($plan->shortage));
            // 10 × 3.0000 + 5 × 4.0000
            $this->assertSame('50.0000', (string) $plan->estimatedCost);
        }
    }

    public function test_equal_cost_prefers_the_larger_balance_so_fewer_locations_are_used(): void
    {
        $balances = [$this->balance(1, '4', '2.0000'), $this->balance(2, '9', '2.0000'), $this->balance(3, '6', '2.0000')];

        $plan = ($this->allocate)(BigDecimal::of('10'), $balances, MaterialSelectionStrategy::LowestInternalCost);

        $this->assertSame([[2, '9'], [3, '1']], $this->draw($plan));
    }

    public function test_fewest_packages_minimises_locations_before_cost(): void
    {
        // Cheapest two balances (2 and 3) cost less in total, but one location alone covers 10.
        $balances = [$this->balance(1, '12', '9.0000'), $this->balance(2, '6', '1.0000'), $this->balance(3, '6', '1.0000')];

        $plan = ($this->allocate)(BigDecimal::of('10'), $balances, MaterialSelectionStrategy::FewestPackages);

        $this->assertSame([[1, '10']], $this->draw($plan));
        $this->assertSame(1, $plan->pickCount());
    }

    public function test_fewest_packages_breaks_a_pick_count_tie_on_cost_among_all_combinations(): void
    {
        // No single location covers 10; three pairs do. Pair {2,3} is cheapest overall even though
        // it does not contain the largest balance, so a largest-first shortcut would get this wrong.
        $balances = [$this->balance(1, '8', '9.0000'), $this->balance(2, '6', '1.0000'), $this->balance(3, '6', '2.0000')];

        $plan = ($this->allocate)(BigDecimal::of('10'), $balances, MaterialSelectionStrategy::FewestPackages);

        $this->assertSame([[2, '6'], [3, '4']], $this->draw($plan));
        $this->assertSame('14.0000', (string) $plan->estimatedCost);
    }

    public function test_a_shortage_draws_everything_usable_and_reports_the_rest(): void
    {
        $balances = [$this->balance(1, '3', '2.0000'), $this->balance(2, '4', '2.0000')];

        foreach (MaterialSelectionStrategy::cases() as $strategy) {
            $plan = ($this->allocate)(BigDecimal::of('10'), $balances, $strategy);

            $this->assertSame('7', $this->plain($plan->covered), $strategy->value);
            $this->assertSame('3', $this->plain($plan->shortage), $strategy->value);
            $this->assertCount(2, $plan->lines, $strategy->value);
        }
    }

    public function test_no_usable_stock_is_a_full_shortage_with_no_invented_cost(): void
    {
        $plan = ($this->allocate)(BigDecimal::of('5'), [$this->balance(1, '0', '2.0000')], MaterialSelectionStrategy::MinimiseWaste);

        $this->assertSame([], $plan->lines);
        $this->assertSame('0.0000', (string) $plan->covered);
        $this->assertSame('5', $this->plain($plan->shortage));
        $this->assertNull($plan->estimatedCost);
    }

    public function test_a_balance_without_cost_ranks_last_and_makes_the_estimate_unknown_rather_than_zero(): void
    {
        $balances = [$this->balance(1, '10', null), $this->balance(2, '10', '7.0000')];

        $plan = ($this->allocate)(BigDecimal::of('15'), $balances, MaterialSelectionStrategy::LowestInternalCost);

        $this->assertSame([[2, '10'], [1, '5']], $this->draw($plan));
        $this->assertNull($plan->estimatedCost);
        $this->assertNull($plan->lines[1]->lineCost);
        $this->assertSame('70.0000', (string) $plan->lines[0]->lineCost);
    }

    public function test_inputs_in_any_order_give_the_same_plan(): void
    {
        $balances = [$this->balance(1, '5', '2.0000'), $this->balance(2, '5', '2.0000'), $this->balance(3, '5', '2.0000')];

        foreach (MaterialSelectionStrategy::cases() as $strategy) {
            $forward = ($this->allocate)(BigDecimal::of('7'), $balances, $strategy);
            $reversed = ($this->allocate)(BigDecimal::of('7'), array_reverse($balances), $strategy);

            $this->assertSame($forward->signature(), $reversed->signature(), $strategy->value);
        }
    }

    public function test_criteria_the_quantity_only_mode_cannot_evaluate_are_reported_not_silently_dropped(): void
    {
        $plan = ($this->allocate)(BigDecimal::of('5'), [$this->balance(1, '10', '2.0000')], MaterialSelectionStrategy::MinimiseWaste);

        $byCode = [];
        foreach ($plan->criteria as $criterion) {
            $byCode[$criterion->code] = $criterion->applied;
        }

        $this->assertSame([
            'OPEN_REMNANTS' => false,
            'EARLIEST_EXPIRY' => false,
            'LEAST_SURPLUS' => false,
            'LOWEST_INTERNAL_COST' => true,
            'FEWEST_PICKS' => true,
        ], $byCode);
    }

    public function test_the_cost_criterion_is_reported_as_not_applied_when_no_balance_has_cost_evidence(): void
    {
        $plan = ($this->allocate)(BigDecimal::of('5'), [$this->balance(1, '10', null)], MaterialSelectionStrategy::LowestInternalCost);

        $this->assertFalse($plan->criteria[0]->applied);
        $this->assertSame('LOWEST_INTERNAL_COST', $plan->criteria[0]->code);
    }

    public function test_an_oversized_combination_search_falls_back_to_a_fewest_picks_plan_and_says_so(): void
    {
        // 40 equal balances of 3 covering 10 needs 4 picks; C(40,4) = 91,390 exceeds the search bound.
        $balances = [];
        for ($id = 1; $id <= 40; $id++) {
            $balances[] = $this->balance($id, '3', '1.0000');
        }

        $plan = ($this->allocate)(BigDecimal::of('10'), $balances, MaterialSelectionStrategy::FewestPackages);

        $this->assertSame(4, $plan->pickCount());
        $this->assertSame('0', $this->plain($plan->shortage));
        $this->assertCount(1, $plan->limitations);
    }

    /** @return list<array{0: int, 1: string}> location id and drawn quantity per line, in draw order */
    private function draw(InventoryAllocation $plan): array
    {
        return array_map(
            fn ($line): array => [$line->balance->locationId, (string) $line->quantity->stripTrailingZeros()],
            $plan->lines,
        );
    }

    /** Quantity text without trailing zeros: these fixtures use unscaled numbers, so compare by value. */
    private function plain(BigDecimal $quantity): string
    {
        return (string) $quantity->stripTrailingZeros();
    }

    private function balance(int $locationId, string $usable, ?string $unitCost): StockBalance
    {
        return new StockBalance(
            itemId: 1,
            warehouseId: 1,
            warehouseCode: 'main',
            warehouseName: 'Main',
            locationId: $locationId,
            locationCode: "loc-{$locationId}",
            locationName: "Location {$locationId}",
            locationType: LocationType::Storage,
            locationUsable: true,
            batchId: null,
            batchCode: null,
            onHand: BigDecimal::of($usable),
            held: BigDecimal::zero(),
            unusableLocation: BigDecimal::zero(),
            usable: BigDecimal::of($usable),
            unitCost: $unitCost === null ? null : BigDecimal::of($unitCost),
            costMethod: $unitCost === null ? null : 'AVCO_POOL',
        );
    }
}

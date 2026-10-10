<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Actions;

use App\Modules\Estimating\Support\AllocationCriterion;
use App\Modules\Estimating\Support\AllocationCriterionReport;
use App\Modules\Estimating\Support\InventoryAllocation;
use App\Modules\Estimating\Support\InventoryAllocationLine;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Support\StockBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns one item's requirement and its usable location balances into an
 * explainable draw plan under an ADR 0053 preset. A pure function: it reads
 * nothing, writes nothing and reserves nothing.
 *
 * This is the QUANTITY_ONLY tracking mode (ADR 0052): the only physical fact
 * is aggregate quantity per location/batch, so open remnants, sealed
 * packages, expiry and package surplus simply do not exist to be compared.
 * Those criteria are reported as not applied, with the reason, and the
 * ordering continues with the ones that can be evaluated — cost and picks.
 * The plan is therefore a quantity draw per location, never a claim about
 * sealed packs or containers.
 *
 * Selection is lexicographic, with the stable balance key as the final
 * tie-breaker, so the same inputs always give the same plan:
 *
 *  - Cost-first presets (MINIMISE_WASTE, LOWEST_INTERNAL_COST) fill cheapest
 *    balance first, larger balance first among equals. For divisible quantity
 *    that minimises cost, and among minimum-cost plans it minimises picks.
 *  - FEWEST_PACKAGES finds the smallest number of balances that can cover the
 *    requirement, then among equally-sized sets picks the cheapest fill. That
 *    set search is bounded (COMBINATION_LIMIT); past it the plan is still a
 *    minimum-pick plan but the cost tie-break is skipped, and the response
 *    says so rather than implying a full search.
 *
 * Balances without complete cost evidence rank after every costed one and make the
 * plan's estimated cost unknown; missing cost is never treated as zero, and a genuine
 * zero cost is a real cost that ranks first.
 *
 * Bump RULES_VERSION whenever the ordering rules change, so a stored or
 * compared result can tell which rules produced it.
 */
final class AllocateInventory
{
    /** 2: cost evidence comes from Inventory's cost query, so a genuine zero cost now ranks as the cheapest balance. */
    public const RULES_VERSION = 2;

    private const COMBINATION_LIMIT = 2000;

    /** @param list<StockBalance> $balances */
    public function __invoke(BigDecimal $required, array $balances, MaterialSelectionStrategy $strategy): InventoryAllocation
    {
        $candidates = array_values(array_filter(
            $balances,
            fn (StockBalance $balance): bool => $balance->usable->isGreaterThan(0),
        ));
        usort($candidates, fn (StockBalance $a, StockBalance $b): int => strcmp($a->key(), $b->key()));

        $criteria = $this->criteriaReport($strategy, $candidates);
        $limitations = [];

        if ($required->isLessThanOrEqualTo(0) || $candidates === []) {
            return new InventoryAllocation($strategy, [], BigDecimal::zero()->toScale(4), $this->nonNegative($required), null, $criteria, $limitations);
        }

        $costed = count(array_filter($candidates, fn (StockBalance $b): bool => $b->unitCost !== null));
        if ($strategy->ranksCostBeforePicks() && $costed > 0 && $costed < count($candidates)) {
            $limitations[] = 'Some usable balances have no complete cost evidence, so this plan is the cheapest only among the balances that do; the others rank last, not cheapest.';
        }

        $draws = $strategy->ranksCostBeforePicks()
            ? $this->fillInOrder($required, $this->costFirstOrder($candidates))
            : $this->fewestPicksDraws($required, $candidates, $limitations);

        $lines = array_map(
            fn (array $draw): InventoryAllocationLine => new InventoryAllocationLine(
                $draw['balance'],
                $draw['quantity'],
                $draw['balance']->unitCost?->multipliedBy($draw['quantity'])->toScale(4, RoundingMode::HALF_UP),
            ),
            $draws,
        );

        $covered = array_reduce($lines, fn (BigDecimal $sum, InventoryAllocationLine $line): BigDecimal => $sum->plus($line->quantity), BigDecimal::zero()->toScale(4));
        $fullyCosted = $lines !== [] && array_reduce($lines, fn (bool $ok, InventoryAllocationLine $line): bool => $ok && $line->lineCost !== null, true);
        $estimatedCost = $fullyCosted
            ? array_reduce($lines, fn (BigDecimal $sum, InventoryAllocationLine $line): BigDecimal => $sum->plus($line->lineCost), BigDecimal::zero())
            : null;

        return new InventoryAllocation(
            $strategy,
            $lines,
            $covered,
            $this->nonNegative($required->minus($covered)),
            $estimatedCost,
            $criteria,
            $limitations,
        );
    }

    /**
     * @param  list<StockBalance>  $ordered
     * @return list<array{balance: StockBalance, quantity: BigDecimal}>
     */
    private function fillInOrder(BigDecimal $required, array $ordered): array
    {
        $remaining = $required;
        $draws = [];
        foreach ($ordered as $balance) {
            if ($remaining->isLessThanOrEqualTo(0)) {
                break;
            }
            $take = $balance->usable->isLessThan($remaining) ? $balance->usable : $remaining;
            $draws[] = ['balance' => $balance, 'quantity' => $take];
            $remaining = $remaining->minus($take);
        }

        return $draws;
    }

    /**
     * @param  list<StockBalance>  $candidates
     * @return list<StockBalance>
     */
    private function costFirstOrder(array $candidates): array
    {
        usort($candidates, function (StockBalance $a, StockBalance $b): int {
            if (($a->unitCost === null) !== ($b->unitCost === null)) {
                return $a->unitCost === null ? 1 : -1;
            }
            if ($a->unitCost !== null && ! $a->unitCost->isEqualTo($b->unitCost)) {
                return $a->unitCost->compareTo($b->unitCost);
            }
            $bySize = $b->usable->compareTo($a->usable);

            return $bySize !== 0 ? $bySize : strcmp($a->key(), $b->key());
        });

        return $candidates;
    }

    /**
     * @param  list<StockBalance>  $candidates
     * @param  list<string>  $limitations
     * @return list<array{balance: StockBalance, quantity: BigDecimal}>
     */
    private function fewestPicksDraws(BigDecimal $required, array $candidates, array &$limitations): array
    {
        $bySize = $candidates;
        usort($bySize, function (StockBalance $a, StockBalance $b): int {
            $size = $b->usable->compareTo($a->usable);

            return $size !== 0 ? $size : strcmp($a->key(), $b->key());
        });

        $total = array_reduce($bySize, fn (BigDecimal $sum, StockBalance $b): BigDecimal => $sum->plus($b->usable), BigDecimal::zero());
        if ($total->isLessThanOrEqualTo($required)) {
            // Everything usable is needed (or is not enough): there is only one plan.
            return $this->fillInOrder($required, $this->costFirstOrder($bySize));
        }

        $size = 0;
        $running = BigDecimal::zero();
        foreach ($bySize as $balance) {
            $running = $running->plus($balance->usable);
            $size++;
            if ($running->isGreaterThanOrEqualTo($required)) {
                break;
            }
        }

        if ($this->combinationCount(count($bySize), $size) > self::COMBINATION_LIMIT) {
            $limitations[] = 'Too many equally-sized location combinations to compare; the fewest-picks plan was chosen but its cost tie-break was skipped.';

            return $this->fillInOrder($required, array_slice($bySize, 0, $size));
        }

        // The largest `$size` balances always cover the requirement (that is how `$size` was
        // found), so they seed the search and it can never come back empty.
        $bestDraws = $this->fillInOrder($required, $this->costFirstOrder(array_slice($bySize, 0, $size)));
        $bestRank = $this->planRank($bestDraws);
        foreach ($this->combinations($bySize, $size) as $set) {
            $sum = array_reduce($set, fn (BigDecimal $s, StockBalance $b): BigDecimal => $s->plus($b->usable), BigDecimal::zero());
            if ($sum->isLessThan($required)) {
                continue;
            }
            $draws = $this->fillInOrder($required, $this->costFirstOrder($set));
            $rank = $this->planRank($draws);
            if ($this->isBetter($rank, $bestRank)) {
                $bestDraws = $draws;
                $bestRank = $rank;
            }
        }

        return $bestDraws;
    }

    /**
     * @param  list<array{balance: StockBalance, quantity: BigDecimal}>  $draws
     * @return array{costed: bool, cost: BigDecimal, keys: string}
     */
    private function planRank(array $draws): array
    {
        $costed = true;
        $cost = BigDecimal::zero();
        foreach ($draws as $draw) {
            if ($draw['balance']->unitCost === null) {
                $costed = false;

                continue;
            }
            $cost = $cost->plus($draw['balance']->unitCost->multipliedBy($draw['quantity']));
        }
        $keys = array_map(fn (array $draw): string => $draw['balance']->key(), $draws);
        sort($keys);

        return ['costed' => $costed, 'cost' => $cost, 'keys' => implode(',', $keys)];
    }

    /**
     * @param  array{costed: bool, cost: BigDecimal, keys: string}  $candidate
     * @param  array{costed: bool, cost: BigDecimal, keys: string}  $incumbent
     */
    private function isBetter(array $candidate, array $incumbent): bool
    {
        if ($candidate['costed'] !== $incumbent['costed']) {
            return $candidate['costed'];
        }
        $byCost = $candidate['cost']->compareTo($incumbent['cost']);

        return $byCost !== 0 ? $byCost < 0 : strcmp($candidate['keys'], $incumbent['keys']) < 0;
    }

    /** A shortage is never negative. */
    private function nonNegative(BigDecimal $value): BigDecimal
    {
        return $value->isLessThan(0) ? BigDecimal::zero() : $value;
    }

    private function combinationCount(int $n, int $k): int
    {
        $count = 1;
        for ($i = 1; $i <= $k; $i++) {
            $count = intdiv($count * ($n - $k + $i), $i);
            if ($count > self::COMBINATION_LIMIT) {
                return $count;
            }
        }

        return $count;
    }

    /**
     * @param  list<StockBalance>  $items
     * @return \Generator<int, list<StockBalance>>
     */
    private function combinations(array $items, int $size, int $start = 0): \Generator
    {
        if ($size === 0) {
            yield [];

            return;
        }
        for ($i = $start; $i <= count($items) - $size; $i++) {
            foreach ($this->combinations($items, $size - 1, $i + 1) as $rest) {
                yield [$items[$i], ...$rest];
            }
        }
    }

    /**
     * @param  list<StockBalance>  $candidates
     * @return list<AllocationCriterionReport>
     */
    private function criteriaReport(MaterialSelectionStrategy $strategy, array $candidates): array
    {
        $costed = count(array_filter($candidates, fn (StockBalance $b): bool => $b->unitCost !== null));

        return array_map(function (AllocationCriterion $criterion) use ($candidates, $costed): AllocationCriterionReport {
            [$applied, $note] = match ($criterion) {
                AllocationCriterion::OpenRemnants => [false, 'Open remnants are not tracked in QUANTITY_ONLY mode.'],
                AllocationCriterion::EarliestExpiry => [false, 'Stock batches carry no expiry date.'],
                AllocationCriterion::LeastSurplus => [false, 'Quantity is drawn exactly per location, so no package surplus arises.'],
                AllocationCriterion::FewestNewPackages => [false, 'Sealed packages are not tracked in QUANTITY_ONLY mode.'],
                AllocationCriterion::LowestInternalCost => match (true) {
                    $costed === 0 => [false, $candidates === [] ? 'No usable stock to compare.' : 'No usable balance has defensible cost evidence.'],
                    $costed < count($candidates) => [true, 'Balances without cost evidence rank after every costed balance.'],
                    default => [true, 'Compared on estimated internal unit cost.'],
                },
                AllocationCriterion::FewestPicks => [true, 'Compared on the number of locations drawn from.'],
            };

            return new AllocationCriterionReport($criterion->value, $criterion->label(), $applied, $note);
        }, $strategy->criteria());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Allocation;

use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\StorageAmount;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;

/**
 * Allocates an already-settled amount across eligible components in whole quanta of the currency, by the largest-remainder rule (ADR 0120, FR4).
 *
 *  1. The total must be a whole number of quanta (a settled amount); its sign is carried aside and the magnitude is divided, so a negative residual is the
 *     mirror image of a positive one.
 *  2. Each eligible component's exact share is total × weight ÷ Σ weights, a fraction. Its integer part, in quanta, is allocated first.
 *  3. The quanta still unplaced go one each to the components with the largest fractional remainders. Equal remainders are ordered by the component's stable
 *     key, ascending (byte order), never by position — so the same inputs give the same result however they are presented, and on every replay.
 *  4. A cap limits what a component may take. A component that would exceed its cap is fixed at the cap and the rest is divided again among the others; if
 *     they cannot hold it all, the allocation is refused.
 *
 * Ineligible components receive exactly zero. The shares sum to the total exactly (checked, and refused if not). All arithmetic is integer and rational;
 * no float is involved and no amount is rounded. The settlement rule supplies the currency and its quantum — the rounding mode plays no part, because
 * largest-remainder places whole quanta rather than rounding. Internal and persistence-free: it checks no permission and writes nothing.
 */
final class AllocateFinancialResiduals
{
    /**
     * @param  list<mixed>  $components  AllocationShare values; anything else is refused rather than trusted
     */
    public function __invoke(string|BigDecimal $total, array $components, SettlementRule $rule): FinancialAllocation
    {
        $places = $rule->quantum->decimalPlaces;
        $signed = $this->total($total, $places);
        $negative = $signed->isNegative();
        $units = $signed->abs()->withPointMovedRight($places)->toBigInteger();

        $byKey = $this->validated($components);
        if ($byKey === []) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_NO_ELIGIBLE_COMPONENT, 'There is nothing to allocate across; an empty set is not a known zero.', 'components');
        }
        $eligible = array_filter($byKey, static fn (AllocationShare $share): bool => $share->eligible);
        if (! $units->isZero() && $eligible === []) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_NO_ELIGIBLE_COMPONENT, 'There is an amount to allocate and no eligible component to take it.', 'components');
        }

        $weightTotal = $this->weightTotal($eligible);
        if (! $units->isZero() && $weightTotal->isZero()) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_NO_WEIGHT, 'Every eligible component weighs zero, so there is no basis to allocate on.', 'weight');
        }

        [$placed, $extra, $capped] = $units->isZero() ? [array_map(static fn (): BigInteger => BigInteger::zero(), $eligible), [], []] : $this->place($units, $eligible, $places);

        $quantum = BigDecimal::ofUnscaledValue(1, $places);
        $shares = [];
        foreach ($byKey as $key => $component) {
            $key = (string) $key;
            if (! $component->eligible) {
                $shares[] = new AllocatedShare($key, false, $component->reason, null, null, BigRational::zero(), $this->money(BigInteger::zero(), $quantum, false), false, false);

                continue;
            }
            $raw = $weightTotal->isZero() ? BigRational::zero() : BigRational::of($units)->multipliedBy(BigRational::of($component->weight))->dividedBy(BigRational::of($weightTotal));
            $rawMoney = $raw->multipliedBy(BigRational::of($quantum));
            $shares[] = new AllocatedShare($key, true, null, $component->weight, $component->cap, $negative ? $rawMoney->negated() : $rawMoney,
                $this->money($placed[$key], $quantum, $negative), isset($extra[$key]), isset($capped[$key]));
        }

        return new FinancialAllocation($signed, $rule, $shares);
    }

    private function total(string|BigDecimal $total, int $places): BigDecimal
    {
        $text = (string) $total;
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_TOTAL_INVALID, 'The total is not an exact decimal number.', 'total');
        }
        $value = BigDecimal::of($text);
        if ($value->strippedOfTrailingZeros()->getScale() > $places) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_TOTAL_INVALID, "The total is not a whole number of the currency's {$places}-place quanta; settle it first.", 'total');
        }
        if ($value->abs()->isGreaterThan(StorageAmount::MAX)) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_TOTAL_INVALID, 'The total is larger than storage can hold.', 'total');
        }

        return $value->toScale(StorageAmount::SCALE);
    }

    /**
     * @param  list<mixed>  $components
     * @return array<string, AllocationShare> in ascending byte order of key
     */
    private function validated(array $components): array
    {
        $byKey = [];
        foreach ($components as $component) {
            if (! $component instanceof AllocationShare) {
                throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_COMPONENT_INVALID, 'Every component must be an AllocationShare.', 'components');
            }
            if (isset($byKey[$component->key])) {
                throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_COMPONENT_INVALID, "The component key [{$component->key}] is used twice.", 'key');
            }
            $byKey[$component->key] = $component;
        }
        uksort($byKey, static fn ($a, $b): int => strcmp((string) $a, (string) $b));

        return $byKey;
    }

    /** @param  array<string, AllocationShare>  $eligible */
    private function weightTotal(array $eligible): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($eligible as $component) {
            $sum = $sum->plus($component->weight);
        }

        return $sum;
    }

    /**
     * Places `$units` quanta among the eligible components, honouring caps. Returns the integer quanta per key, the keys that received an extra quantum
     * from the remainder rule in their final pass, and the keys fixed at their cap.
     *
     * @param  array<string, AllocationShare>  $eligible
     * @return array{0: array<string, BigInteger>, 1: array<string, true>, 2: array<string, true>}
     */
    private function place(BigInteger $units, array $eligible, int $places): array
    {
        $fixed = [];
        $capped = [];
        $active = $eligible;
        $remaining = $units;

        while (true) {
            if ($active === []) {
                throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_CAPACITY_EXCEEDED, 'The eligible components cannot hold the whole amount within their caps.', 'cap');
            }
            $weight = $this->weightTotal($active);
            if ($weight->isZero()) {
                throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_CAPACITY_EXCEEDED, 'The amount left after the capped components has no weighted component to take it.', 'weight');
            }
            [$pass, $extra] = $this->largestRemainder($remaining, $active, $weight);

            $violators = [];
            foreach ($active as $key => $component) {
                $capUnits = $this->capUnits($component, $places);
                if ($capUnits !== null && $pass[$key]->isGreaterThan($capUnits)) {
                    $violators[$key] = $capUnits;
                }
            }
            if ($violators === []) {
                return [$fixed + $pass, $extra, $capped];
            }
            foreach ($violators as $key => $capUnits) {
                $fixed[$key] = $capUnits;
                $capped[$key] = true;
                $remaining = $remaining->minus($capUnits);
                unset($active[$key]);
            }
        }
    }

    /**
     * @param  array<string, AllocationShare>  $active
     * @return array{0: array<string, BigInteger>, 1: array<string, true>}
     */
    private function largestRemainder(BigInteger $units, array $active, BigDecimal $weightTotal): array
    {
        $base = [];
        $remainders = [];
        $placed = BigInteger::zero();
        foreach ($active as $key => $component) {
            $share = BigRational::of($units)->multipliedBy(BigRational::of($component->weight))->dividedBy(BigRational::of($weightTotal));
            $whole = $share->getNumerator()->quotient($share->getDenominator());
            $base[$key] = $whole;
            $remainders[$key] = $share->minus(BigRational::of($whole));
            $placed = $placed->plus($whole);
        }
        $left = $units->minus($placed)->toInt();

        $order = array_keys($active);
        usort($order, static function ($a, $b) use ($remainders): int {
            $byRemainder = $remainders[$b]->compareTo($remainders[$a]);

            return $byRemainder !== 0 ? $byRemainder : strcmp((string) $a, (string) $b);
        });
        $extra = [];
        foreach (array_slice($order, 0, $left) as $key) {
            $base[$key] = $base[$key]->plus(1);
            $extra[(string) $key] = true;
        }

        return [$base, $extra];
    }

    /** The most whole quanta a component may take: its cap, floored, because a cap is an upper bound. */
    private function capUnits(AllocationShare $component, int $places): ?BigInteger
    {
        return $component->cap?->withPointMovedRight($places)->toScale(0, RoundingMode::DOWN)->toBigInteger();
    }

    private function money(BigInteger $units, BigDecimal $quantum, bool $negative): BigDecimal
    {
        $amount = BigDecimal::of($units)->multipliedBy($quantum)->toScale(StorageAmount::SCALE);

        return $negative && ! $amount->isZero() ? $amount->negated() : $amount;
    }
}

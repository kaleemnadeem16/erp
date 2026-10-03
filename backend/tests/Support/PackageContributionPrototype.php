<?php

declare(strict_types=1);

namespace Tests\Support;

use Brick\Math\BigDecimal;
use DomainException;

/**
 * Test-only contract spike for proposed ADR 0082, not an operational stock writer.
 * Inputs belong to one already validated company/item/location/batch/QA scope.
 * Contribution order is bookkeeping order, not physical vessel identity.
 */
final class PackageContributionPrototype
{
    /**
     * @param  array<int, string>  $contributions  Ordered layer quantities for this cohort
     * @param  array<int, string>  $actualAllocation  Authoritative movement allocation
     * @param  array<int, string>  $usable  Layer quantities authorised by QA
     * @return array{selected:array<int,string>, remaining:array<int,string>}
     */
    public function select(
        int $count,
        string $content,
        int $takeCount,
        array $contributions,
        array $actualAllocation,
        array $usable,
    ): array {
        if ($count < 1 || $takeCount < 1 || $takeCount > $count) {
            throw new DomainException('Select whole packages within the available count.');
        }

        $unit = $this->quantity($content);
        $pool = $this->vector($contributions);
        if (! $this->sum($pool)->isEqualTo($unit->multipliedBy($count))) {
            throw new DomainException('Cohort contributions do not reconcile with package contents.');
        }

        $required = $unit->multipliedBy($takeCount);
        $selected = [];
        $remaining = [];
        foreach ($pool as $layer => $qty) {
            $take = $qty->isLessThan($required) ? $qty : $required;
            if ($take->isGreaterThan(0)) {
                $selected[$layer] = (string) $take;
            }
            $rest = $qty->minus($take);
            if ($rest->isGreaterThan(0)) {
                $remaining[$layer] = (string) $rest;
            }
            $required = $required->minus($take);
        }

        $actual = $this->vector($actualAllocation);
        if (count($selected) !== count($actual)) {
            throw new DomainException('Physical selection differs from the stock allocation.');
        }
        foreach ($selected as $layer => $qty) {
            if (! isset($actual[$layer]) || ! $actual[$layer]->isEqualTo($qty)) {
                throw new DomainException('Physical selection differs from the stock allocation.');
            }
            $available = isset($usable[$layer]) ? $this->quantity($usable[$layer]) : BigDecimal::zero();
            if ($available->isLessThan($qty)) {
                throw new DomainException('Selected sealed contents include held stock; physical QA evidence is required.');
            }
        }

        return ['selected' => $selected, 'remaining' => $remaining];
    }

    /**
     * One destination layer per actual source allocation, as in TransferPoster.
     *
     * @param  array<int,string>  $selected
     * @param  array<int,int>  $destinationLayers  Source-to-destination mapping from posting
     * @return array<int,string>
     */
    public function transfer(array $selected, array $destinationLayers): array
    {
        $source = $this->vector($selected);
        if (count($source) !== count($destinationLayers)) {
            throw new DomainException('Transfer mapping must cover exactly the selected contributions.');
        }
        $destination = [];
        foreach ($source as $layer => $qty) {
            $target = $destinationLayers[$layer] ?? null;
            if (! is_int($target) || $target < 1 || isset($destination[$target])) {
                throw new DomainException('Transfer requires distinct posted destination layers.');
            }
            $destination[$target] = (string) $qty;
        }

        return $destination;
    }

    private function quantity(string $qty): BigDecimal
    {
        $value = BigDecimal::of($qty)->toScale(4);
        if ($value->isLessThanOrEqualTo(0)) {
            throw new DomainException('Contribution quantities must be positive.');
        }

        return $value;
    }

    /** @param array<int,string> $values
     * @return array<int,BigDecimal>
     */
    private function vector(array $values): array
    {
        if ($values === []) {
            throw new DomainException('A contribution vector is required.');
        }
        $result = [];
        foreach ($values as $layer => $qty) {
            if (! is_int($layer) || $layer < 1) {
                throw new DomainException('A positive stock layer identity is required.');
            }
            $result[$layer] = $this->quantity($qty);
        }

        return $result;
    }

    /** @param array<int,BigDecimal> $values */
    private function sum(array $values): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($values as $qty) {
            $sum = $sum->plus($qty);
        }

        return $sum;
    }
}

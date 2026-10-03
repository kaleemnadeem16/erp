<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/** Exact cohort transformation. Contributions represent bookkeeping, not vessel identities. */
final class PackageOpeningComposition
{
    /**
     * @param  list<array<string,mixed>>  $composition
     * @param  array<string,mixed>|null  $inner
     * @return list<array<string,mixed>>
     */
    public function __invoke(array $composition, int $stateId, int $count, string $kind, ?array $inner = null, ?int $innerCount = null): array
    {
        if (! in_array($kind, ['OPEN', 'UNPACK'], true) || $count < 1) {
            throw new DomainInvariantException('Choose OPEN or UNPACK and a positive whole package count.');
        }
        $result = [];
        $found = false;
        foreach ($composition as $row) {
            if ($row['state_id'] !== $stateId) {
                $result[] = $row;

                continue;
            }
            $found = true;
            if ($row['form'] !== 'SEALED' || $row['availability_evidence'] !== 'USABLE' || $count > $row['sealed_count']) {
                throw new DomainInvariantException('Only available whole sealed packages can be opened or unpacked.');
            }
            $quantity = $this->exact($row['content_quantity'])->multipliedBy($count)->toScale(4);
            $target = $row;
            unset($target['state_id']); // Proposed rows have no persisted identity.
            if ($kind === 'OPEN') {
                $target = array_replace($target, ['form' => 'OPEN_LOOSE', 'packaging_id' => null,
                    'packaging_code' => null, 'packaging_name' => null, 'sealed_count' => null, 'content_quantity' => null]);
            } else {
                if ($inner === null || $innerCount === null || $innerCount < 1
                    || ! $this->exact($inner['content_quantity'])->multipliedBy($innerCount)->isEqualTo($row['content_quantity'])) {
                    throw new DomainInvariantException('Inner sealed contents must exactly reconcile to the outer package.');
                }
                $innerTotal = BigDecimal::of($count)->multipliedBy($innerCount);
                if ($innerTotal->isGreaterThan(2147483647)) {
                    throw new DomainInvariantException('Unpacked count exceeds supported package-count storage.');
                }
                $target = array_replace($target, $inner, ['sealed_count' => $innerTotal->toInt()]);
            }
            [$taken, $remaining] = $this->split($row['contributions'], $quantity);
            $target['quantity'] = (string) $quantity;
            $target['contributions'] = $taken;
            if ($count < $row['sealed_count']) {
                unset($row['state_id']);
                $row['sealed_count'] -= $count;
                $row['quantity'] = (string) $this->exact($row['quantity'])->minus($quantity)->toScale(4);
                $row['contributions'] = $remaining;
                $result[] = $row;
            }
            $result[] = $target;
        }
        if (! $found) {
            throw new DomainInvariantException('Selected package state does not belong to this composition.');
        }
        // The first release groups open contents within this one traceability scope.
        $loose = null;
        $sealed = [];
        foreach ($result as $row) {
            if ($row['form'] !== 'OPEN_LOOSE' || $row['availability_evidence'] !== 'USABLE') {
                $sealed[] = $row;

                continue;
            }
            if ($loose === null) {
                $loose = $row;
                unset($loose['state_id']);

                continue;
            }
            $loose['quantity'] = (string) $this->exact($loose['quantity'])->plus($row['quantity'])->toScale(4);
            $parts = [];
            foreach (array_merge($loose['contributions'], $row['contributions']) as $part) {
                $parts[$part['layer_id']] = ($parts[$part['layer_id']] ?? BigDecimal::zero())->plus($part['quantity']);
            }
            $loose['contributions'] = [];
            foreach ($parts as $layer => $quantity) {
                $loose['contributions'][] = ['layer_id' => $layer, 'position' => count($loose['contributions']) + 1, 'quantity' => (string) $quantity->toScale(4)];
            }
        }
        if ($loose !== null) {
            $sealed[] = $loose;
        }

        return $sealed;
    }

    /**
     * @param  list<array{layer_id:int,position:int,quantity:string}>  $parts
     * @return array{list<array{layer_id:int,position:int,quantity:string}>,list<array{layer_id:int,position:int,quantity:string}>}
     */
    private function split(array $parts, BigDecimal $needed): array
    {
        $taken = [];
        $remaining = [];
        foreach ($parts as $part) {
            $available = $this->exact($part['quantity']);
            $take = $available->isLessThan($needed) ? $available : $needed;
            if ($take->isGreaterThan(0)) {
                $taken[] = ['layer_id' => $part['layer_id'], 'position' => count($taken) + 1, 'quantity' => (string) $take];
            }
            $rest = $available->minus($take);
            if ($rest->isGreaterThan(0)) {
                $remaining[] = ['layer_id' => $part['layer_id'], 'position' => count($remaining) + 1, 'quantity' => (string) $rest];
            }
            $needed = $needed->minus($take);
        }
        if (! $needed->isZero()) {
            throw new DomainInvariantException('Package contributions do not cover the selected contents.');
        }

        return [$taken, $remaining];
    }

    private function exact(string $quantity): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity)->toScale(4);
            if ($value->isGreaterThan(0)) {
                return $value;
            }
        } catch (\InvalidArgumentException|MathException $error) {
            // Invalid precision is a domain error, never rounded.
        }
        throw new DomainInvariantException('Package contents require positive exact stock-precision quantities.');
    }
}

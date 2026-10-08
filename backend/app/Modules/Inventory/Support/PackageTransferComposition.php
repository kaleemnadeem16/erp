<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;

/**
 * The destination's successor composition for a grouped package transfer (ADR 0090 §9). Pure arithmetic, deterministic, no database.
 *
 * What arrives is exactly what was declared at the source: whole sealed packages arrive sealed, and loose quantity — including the part of
 * a package that was opened for the transfer — arrives as open/loose stock. Each declared line is mapped, in order, onto the destination
 * layers the transfer actually created (taken in the allocator's order), and merged into the destination's existing states of the same
 * identity so there is still one open/loose quantity per scope. Nothing is inferred, repacked or re-sealed, and no cost is involved.
 */
final class PackageTransferComposition
{
    /**
     * @param  list<array<string,mixed>>  $destination  replayed rows of the destination's fresh evidence (empty when it held nothing)
     * @param  list<array<string,mixed>>  $source  replayed rows the draw was applied to, for package identity snapshots
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>  $draws
     * @param  list<array{layer_id:int, quantity:string}>  $incoming  destination layers created by the transfer, in allocator order
     * @return list<array<string,mixed>> successor rows; retained rows carry origin_state_id
     */
    public function __invoke(array $destination, array $source, array $draws, array $incoming, string $availability = 'USABLE'): array
    {
        $rows = [];
        foreach ($destination as $row) {
            $row['origin_state_id'] = $row['state_id'];
            unset($row['state_id']);
            $rows[] = $row;
        }
        $queue = array_map(fn (array $part): array => ['layer_id' => $part['layer_id'], 'quantity' => BigDecimal::of($part['quantity'])], $incoming);

        foreach ($draws as $draw) {
            $contributions = $this->take($queue, BigDecimal::of($draw['quantity']));
            if ($draw['kind'] === 'SEALED') {
                $identity = $this->identity($source, (int) $draw['packaging_id'], $availability);
                $index = $this->find($rows, $identity, $availability);
                if ($index === null) {
                    $rows[] = ['origin_state_id' => null, 'form' => 'SEALED', 'availability_evidence' => $availability, 'packaging_id' => $identity['packaging_id'],
                        'packaging_code' => $identity['packaging_code'], 'packaging_name' => $identity['packaging_name'], 'sealed_count' => $draw['count'],
                        'content_quantity' => $identity['content_quantity'], 'quantity' => (string) $draw['quantity']->toScale(4), 'contributions' => $this->number($contributions)];
                } else {
                    $rows[$index]['sealed_count'] += $draw['count'];
                    $rows[$index] = $this->merge($rows[$index], $draw['quantity'], $contributions);
                }

                continue;
            }
            $index = $this->find($rows, null, $availability);
            if ($index === null) {
                $rows[] = ['origin_state_id' => null, 'form' => 'OPEN_LOOSE', 'availability_evidence' => $availability, 'packaging_id' => null, 'packaging_code' => null,
                    'packaging_name' => null, 'sealed_count' => null, 'content_quantity' => null, 'quantity' => (string) $draw['quantity']->toScale(4), 'contributions' => $this->number($contributions)];
            } else {
                $rows[$index] = $this->merge($rows[$index], $draw['quantity'], $contributions);
            }
        }
        foreach ($queue as $left) {
            if ($left['quantity']->isGreaterThan(0)) {
                throw new DomainInvariantException('The transfer created destination stock that the declared draw does not account for.');
            }
        }

        return $rows;
    }

    /**
     * @param  list<array<string,mixed>>  $source
     * @return array{packaging_id:int, packaging_code:string, packaging_name:string, content_quantity:string}
     */
    private function identity(array $source, int $packagingId, string $availability): array
    {
        foreach ($source as $row) {
            if ($row['form'] === 'SEALED' && $row['availability_evidence'] === $availability && $row['packaging_id'] === $packagingId) {
                return ['packaging_id' => $packagingId, 'packaging_code' => $row['packaging_code'], 'packaging_name' => $row['packaging_name'], 'content_quantity' => $row['content_quantity']];
            }
        }
        throw new DomainInvariantException('The transferred package is not on hand at the source.');
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array{packaging_id:int, packaging_code:string, packaging_name:string, content_quantity:string}|null  $identity  null selects the open/loose row
     */
    private function find(array $rows, ?array $identity, string $availability): ?int
    {
        foreach ($rows as $index => $row) {
            if ($row['availability_evidence'] !== $availability) {
                continue;
            }
            if ($identity === null && $row['form'] === 'OPEN_LOOSE') {
                return $index;
            }
            if ($identity !== null && $row['form'] === 'SEALED' && $row['packaging_id'] === $identity['packaging_id']
                && $row['packaging_code'] === $identity['packaging_code'] && $row['packaging_name'] === $identity['packaging_name']
                && BigDecimal::of($row['content_quantity'])->isEqualTo($identity['content_quantity'])) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  list<array{layer_id:int, quantity:BigDecimal}>  $contributions
     * @return array<string,mixed>
     */
    private function merge(array $row, BigDecimal $quantity, array $contributions): array
    {
        $row['quantity'] = (string) BigDecimal::of($row['quantity'])->plus($quantity)->toScale(4);
        $parts = $row['contributions'];
        foreach ($contributions as $add) {
            $merged = false;
            foreach ($parts as $i => $part) {
                if ($part['layer_id'] === $add['layer_id']) {
                    $parts[$i]['quantity'] = (string) BigDecimal::of($part['quantity'])->plus($add['quantity'])->toScale(4);
                    $merged = true;

                    break;
                }
            }
            if (! $merged) {
                $parts[] = ['layer_id' => $add['layer_id'], 'position' => count($parts) + 1, 'quantity' => (string) $add['quantity']->toScale(4)];
            }
        }
        $row['contributions'] = $parts;

        return $row;
    }

    /**
     * @param  list<array{layer_id:int, quantity:BigDecimal}>  $queue
     * @return list<array{layer_id:int, quantity:BigDecimal}>
     */
    private function take(array &$queue, BigDecimal $needed): array
    {
        $taken = [];
        foreach ($queue as $i => $part) {
            if ($needed->isZero()) {
                break;
            }
            $take = $part['quantity']->isLessThan($needed) ? $part['quantity'] : $needed;
            if ($take->isGreaterThan(0)) {
                $taken[] = ['layer_id' => $part['layer_id'], 'quantity' => $take];
                $queue[$i]['quantity'] = $part['quantity']->minus($take);
                $needed = $needed->minus($take);
            }
        }
        if (! $needed->isZero()) {
            throw new DomainInvariantException('The declared draw is larger than the stock the transfer created at the destination.');
        }

        return $taken;
    }

    /**
     * @param  list<array{layer_id:int, quantity:BigDecimal}>  $parts
     * @return list<array{layer_id:int, position:int, quantity:string}>
     */
    private function number(array $parts): array
    {
        $numbered = [];
        foreach ($parts as $part) {
            $numbered[] = ['layer_id' => $part['layer_id'], 'position' => count($numbered) + 1, 'quantity' => (string) $part['quantity']->toScale(4)];
        }

        return $numbered;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;

/**
 * The successor composition of a grouped package issue (ADR 0090 §4, §7). Pure arithmetic over replayed evidence rows: no database,
 * no stock posting.
 *
 * 1. The physical draw is applied: whole sealed packages and a loose quantity leave their states, taking their own ordered
 *    layer contributions front first.
 * 2. Those contributions are bookkeeping, not provenance, so they are then made to agree with the layers the allocator actually
 *    drew on. Any per-layer difference is moved inside each state, in stable order, latest contribution first, so every state keeps
 *    its quantity and only the "attributed to layer" label moves.
 *
 * Cost is never an input: the allocator decides layers and value, and the person's physical choice does not override either.
 */
final class PackageIssueComposition
{
    /**
     * @param  list<array<string,mixed>>  $composition  replayed rows of the predecessor (state_id, form, availability_evidence, packaging_id, sealed_count, content_quantity, quantity, contributions)
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal|string}>  $draws  SEALED and LOOSE lines only
     * @param  array<int,string>  $vector  layer id => quantity the allocator drew
     * @return list<array<string,mixed>> successor rows; retained rows carry origin_state_id
     */
    public function __invoke(array $composition, array $draws, array $vector, string $from = 'USABLE'): array
    {
        $sealed = [];
        $loose = BigDecimal::zero();
        foreach ($draws as $draw) {
            if ($draw['kind'] === 'SEALED') {
                $sealed[$draw['packaging_id']] = ($sealed[$draw['packaging_id']] ?? 0) + (int) $draw['count'];
            } else {
                $loose = $loose->plus($draw['quantity']);
            }
        }

        $original = [];
        $rows = [];
        foreach ($composition as $row) {
            // Only the rows the draw acts on are re-attributed to the allocator's layers; held and rejected rows keep exactly what they hold (ADR 0094).
            foreach ($row['availability_evidence'] === $from ? $row['contributions'] : [] as $part) {
                $original[$part['layer_id']] = ($original[$part['layer_id']] ?? BigDecimal::zero())->plus($part['quantity']);
            }
            $next = $this->draw($row, $sealed, $loose, $from);
            if ($next !== null) {
                $rows[] = $next;
            }
        }
        foreach ($sealed as $left) {
            if ($left !== 0) {
                throw new DomainInvariantException('The draw names sealed packages that are not available.');
            }
        }
        if (! $loose->isZero()) {
            throw new DomainInvariantException('The draw takes more open/loose quantity than is on hand.');
        }

        return $this->attribute($rows, $original, $vector, $from);
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<int,int>  $sealed  packaging id => whole packages still to take (decremented here)
     * @return array<string,mixed>|null
     */
    private function draw(array $row, array &$sealed, BigDecimal &$loose, string $from): ?array
    {
        $row['origin_state_id'] = $row['state_id'];
        unset($row['state_id']);
        if ($row['availability_evidence'] !== $from) {
            return $row;
        }
        if ($row['form'] === 'SEALED' && isset($sealed[$row['packaging_id']]) && $sealed[$row['packaging_id']] > 0) {
            $take = min($sealed[$row['packaging_id']], (int) $row['sealed_count']);
            $sealed[$row['packaging_id']] -= $take;
            $quantity = BigDecimal::of($row['content_quantity'])->multipliedBy($take);
            $row['contributions'] = $this->split($row['contributions'], $quantity)[1];
            $row['sealed_count'] -= $take;
            $row['quantity'] = (string) BigDecimal::of($row['quantity'])->minus($quantity)->toScale(4);

            return $row['sealed_count'] > 0 ? $row : null;
        }
        if ($row['form'] === 'OPEN_LOOSE' && $loose->isGreaterThan(0)) {
            $take = BigDecimal::of($row['quantity'])->isLessThan($loose) ? BigDecimal::of($row['quantity']) : $loose;
            $loose = $loose->minus($take);
            $row['contributions'] = $this->split($row['contributions'], $take)[1];
            $row['quantity'] = (string) BigDecimal::of($row['quantity'])->minus($take)->toScale(4);

            return BigDecimal::of($row['quantity'])->isGreaterThan(0) ? $row : null;
        }

        return $row;
    }

    /**
     * Make the successor's per-layer totals equal original − allocator vector, keeping each state's total.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<int,BigDecimal>  $original
     * @param  array<int,string>  $vector
     * @return list<array<string,mixed>>
     */
    private function attribute(array $rows, array $original, array $vector, string $from): array
    {
        $target = $original;
        foreach ($vector as $layer => $quantity) {
            $target[$layer] = ($target[$layer] ?? BigDecimal::zero())->minus($quantity);
            if ($target[$layer]->isLessThan(0)) {
                throw new DomainInvariantException('The allocator drew a layer the package evidence does not hold.');
            }
        }
        $current = [];
        foreach ($rows as $row) {
            foreach ($row['availability_evidence'] === $from ? $row['contributions'] : [] as $part) {
                $current[$part['layer_id']] = ($current[$part['layer_id']] ?? BigDecimal::zero())->plus($part['quantity']);
            }
        }
        $over = [];
        $under = [];
        foreach (array_unique(array_merge(array_keys($current), array_keys($target))) as $layer) {
            $difference = ($current[$layer] ?? BigDecimal::zero())->minus($target[$layer] ?? BigDecimal::zero());
            if ($difference->isGreaterThan(0)) {
                $over[$layer] = $difference;
            } elseif ($difference->isLessThan(0)) {
                $under[$layer] = $difference->negated();
            }
        }
        ksort($over);
        ksort($under);

        foreach ($rows as $index => $row) {
            if ($row['availability_evidence'] !== $from) {
                continue;
            }
            $parts = $row['contributions'];
            foreach ($over as $layer => $excess) {
                if ($excess->isZero()) {
                    continue;
                }
                for ($i = count($parts) - 1; $i >= 0 && $excess->isGreaterThan(0); $i--) {
                    if ($parts[$i]['layer_id'] !== $layer) {
                        continue;
                    }
                    $cut = BigDecimal::of($parts[$i]['quantity']);
                    $cut = $cut->isLessThan($excess) ? $cut : $excess;
                    $moved = $cut;
                    foreach ($under as $need => $needed) {
                        if ($moved->isZero()) {
                            break;
                        }
                        if ($needed->isZero()) {
                            continue;
                        }
                        $give = $needed->isLessThan($moved) ? $needed : $moved;
                        $parts = $this->add($parts, $need, $give);
                        $under[$need] = $needed->minus($give);
                        $moved = $moved->minus($give);
                    }
                    $parts[$i]['quantity'] = (string) BigDecimal::of($parts[$i]['quantity'])->minus($cut)->toScale(4);
                    $excess = $excess->minus($cut);
                }
                $over[$layer] = $excess;
            }
            $rows[$index]['contributions'] = $this->renumber($parts);
        }
        foreach ($over as $left) {
            if (! $left->isZero()) {
                throw new DomainInvariantException('Package contributions could not be reconciled to the allocated layers.');
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{layer_id:int,position:int,quantity:string}>  $parts
     * @return list<array{layer_id:int,position:int,quantity:string}>
     */
    private function add(array $parts, int $layer, BigDecimal $quantity): array
    {
        foreach ($parts as $i => $part) {
            if ($part['layer_id'] === $layer) {
                $parts[$i]['quantity'] = (string) BigDecimal::of($part['quantity'])->plus($quantity)->toScale(4);

                return $parts;
            }
        }
        $parts[] = ['layer_id' => $layer, 'position' => count($parts) + 1, 'quantity' => (string) $quantity->toScale(4)];

        return $parts;
    }

    /**
     * @param  list<array{layer_id:int,position:int,quantity:string}>  $parts
     * @return list<array{layer_id:int,position:int,quantity:string}>
     */
    private function renumber(array $parts): array
    {
        $result = [];
        foreach ($parts as $part) {
            if (BigDecimal::of($part['quantity'])->isGreaterThan(0)) {
                $result[] = ['layer_id' => $part['layer_id'], 'position' => count($result) + 1, 'quantity' => (string) BigDecimal::of($part['quantity'])->toScale(4)];
            }
        }

        return $result;
    }

    /**
     * Take `$needed` from the front of the ordered contributions.
     *
     * @param  list<array{layer_id:int,position:int,quantity:string}>  $parts
     * @return array{list<array{layer_id:int,position:int,quantity:string}>, list<array{layer_id:int,position:int,quantity:string}>} [taken, remaining]
     */
    private function split(array $parts, BigDecimal $needed): array
    {
        $taken = [];
        $remaining = [];
        foreach ($parts as $part) {
            $available = BigDecimal::of($part['quantity']);
            $take = $available->isLessThan($needed) ? $available : $needed;
            if ($take->isGreaterThan(0)) {
                $taken[] = ['layer_id' => $part['layer_id'], 'position' => count($taken) + 1, 'quantity' => (string) $take->toScale(4)];
            }
            $rest = $available->minus($take);
            if ($rest->isGreaterThan(0)) {
                $remaining[] = ['layer_id' => $part['layer_id'], 'position' => count($remaining) + 1, 'quantity' => (string) $rest->toScale(4)];
            }
            $needed = $needed->minus($take);
        }
        if (! $needed->isZero()) {
            throw new DomainInvariantException('Package contributions do not cover the drawn contents.');
        }

        return [$taken, $remaining];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;

/**
 * The successor composition of a QA disposition (ADR 0094). Pure arithmetic over replayed evidence rows: a declared portion of whole sealed packages and
 * loose quantity is relabelled from HELD to the outcome's availability. Total quantity and every layer total are unchanged — the receipt layer's share
 * simply moves from the held group to the target group — so no stock, cost or layer is touched. A sealed package is relabelled whole or not at all.
 *
 * Layer attribution inside a group is bookkeeping, not provenance (ADR 0090), so the two groups that change are re-apportioned deterministically over
 * their own per-layer totals; groups that do not change keep their rows exactly.
 */
final class PackageQaComposition
{
    /**
     * @param  list<array<string,mixed>>  $composition  replayed rows of the predecessor
     * @param  list<array{kind:string, packaging_id:int|null, count:int|null, quantity:BigDecimal}>  $draws  SEALED and LOOSE lines, from held stock
     * @return list<array<string,mixed>> successor rows; untouched rows carry origin_state_id
     */
    public function __invoke(array $composition, array $draws, string $target, int $layerId): array
    {
        $held = [];
        $targetRows = [];
        $others = [];
        foreach ($composition as $row) {
            $row['origin_state_id'] = $row['state_id'];
            unset($row['state_id']);
            match ($row['availability_evidence']) {
                'HELD' => $held[] = $row,
                $target => $targetRows[] = $row,
                default => $others[] = $row,
            };
        }
        $heldLayers = $this->layers($held);
        $targetLayers = $this->layers($targetRows);

        $sealed = [];
        $loose = BigDecimal::zero();
        $moved = BigDecimal::zero();
        foreach ($draws as $draw) {
            $moved = $moved->plus($draw['quantity']);
            if ($draw['kind'] === 'SEALED') {
                $sealed[$draw['packaging_id']] = ($sealed[$draw['packaging_id']] ?? 0) + (int) $draw['count'];
            } else {
                $loose = $loose->plus($draw['quantity']);
            }
        }
        $heldLayers[$layerId] = ($heldLayers[$layerId] ?? BigDecimal::zero())->minus($moved);
        if ($heldLayers[$layerId]->isNegative()) {
            throw new DomainInvariantException('The declared portion is larger than the quantity this receipt still holds.', 'package_portion', 'DRAW_NOT_ON_HAND');
        }
        $targetLayers[$layerId] = ($targetLayers[$layerId] ?? BigDecimal::zero())->plus($moved);

        $remainingHeld = [];
        foreach ($held as $row) {
            if ($row['form'] === 'SEALED' && ($sealed[$row['packaging_id']] ?? 0) > 0) {
                $take = min($sealed[$row['packaging_id']], (int) $row['sealed_count']);
                $sealed[$row['packaging_id']] -= $take;
                $this->addTarget($targetRows, $row, $take, BigDecimal::of($row['content_quantity'])->multipliedBy($take), $target);
                $row['sealed_count'] -= $take;
                $row['quantity'] = (string) BigDecimal::of($row['quantity'])->minus(BigDecimal::of($row['content_quantity'])->multipliedBy($take))->toScale(4);
                if ($row['sealed_count'] <= 0) {
                    continue;
                }
            } elseif ($row['form'] === 'OPEN_LOOSE' && $loose->isGreaterThan(0)) {
                $take = BigDecimal::of($row['quantity'])->isLessThan($loose) ? BigDecimal::of($row['quantity']) : $loose;
                $loose = $loose->minus($take);
                $this->addTarget($targetRows, $row, 0, $take, $target);
                $row['quantity'] = (string) BigDecimal::of($row['quantity'])->minus($take)->toScale(4);
                if (BigDecimal::of($row['quantity'])->isLessThanOrEqualTo(0)) {
                    continue;
                }
            }
            $remainingHeld[] = $row;
        }
        foreach ($sealed as $left) {
            if ($left !== 0) {
                throw new DomainInvariantException('The declared portion names sealed packages that are not held, or part of a sealed package that is not wholly held.', 'package_portion', 'DRAW_NOT_ON_HAND');
            }
        }
        if (! $loose->isZero()) {
            throw new DomainInvariantException('The declared portion takes more loose quantity than is held.', 'package_portion', 'DRAW_NOT_ON_HAND');
        }

        return array_merge($others, $this->spread($remainingHeld, $heldLayers), $this->spread($targetRows, $targetLayers));
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<int,BigDecimal>
     */
    private function layers(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            foreach ($row['contributions'] as $part) {
                $totals[$part['layer_id']] = ($totals[$part['layer_id']] ?? BigDecimal::zero())->plus($part['quantity']);
            }
        }

        return $totals;
    }

    /**
     * @param  list<array<string,mixed>>  $targetRows
     * @param  array<string,mixed>  $source  the held row the portion comes from (identity snapshot)
     */
    private function addTarget(array &$targetRows, array $source, int $count, BigDecimal $quantity, string $target): void
    {
        foreach ($targetRows as $index => $row) {
            if ($row['form'] === $source['form'] && $row['packaging_id'] === $source['packaging_id']
                && ($source['form'] === 'OPEN_LOOSE' || BigDecimal::of($row['content_quantity'])->isEqualTo($source['content_quantity']))) {
                $targetRows[$index]['quantity'] = (string) BigDecimal::of($row['quantity'])->plus($quantity)->toScale(4);
                if ($source['form'] === 'SEALED') {
                    $targetRows[$index]['sealed_count'] = (int) $row['sealed_count'] + $count;
                }

                return;
            }
        }
        $targetRows[] = ['origin_state_id' => null, 'form' => $source['form'], 'availability_evidence' => $target, 'packaging_id' => $source['packaging_id'],
            'packaging_code' => $source['packaging_code'], 'packaging_name' => $source['packaging_name'], 'sealed_count' => $source['form'] === 'SEALED' ? $count : null,
            'content_quantity' => $source['content_quantity'], 'quantity' => (string) $quantity->toScale(4), 'contributions' => []];
    }

    /**
     * Give each row, in order, its quantity from the group's layers in ascending layer order.
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<int,BigDecimal>  $layers
     * @return list<array<string,mixed>>
     */
    private function spread(array $rows, array $layers): array
    {
        ksort($layers);
        $remaining = array_filter($layers, fn (BigDecimal $quantity): bool => $quantity->isGreaterThan(0));
        foreach ($rows as $index => $row) {
            $needed = BigDecimal::of($row['quantity']);
            $parts = [];
            foreach ($remaining as $layer => $available) {
                if ($needed->isZero()) {
                    break;
                }
                $take = $available->isLessThan($needed) ? $available : $needed;
                if ($take->isGreaterThan(0)) {
                    $parts[] = ['layer_id' => $layer, 'position' => count($parts) + 1, 'quantity' => (string) $take->toScale(4)];
                    $remaining[$layer] = $available->minus($take);
                    $needed = $needed->minus($take);
                }
            }
            if (! $needed->isZero()) {
                throw new DomainInvariantException('Package contributions could not be reconciled to the layers.');
            }
            $rows[$index]['contributions'] = $parts;
        }
        foreach ($remaining as $left) {
            if ($left->isGreaterThan(0)) {
                throw new DomainInvariantException('Package contributions could not be reconciled to the layers.');
            }
        }

        return $rows;
    }
}

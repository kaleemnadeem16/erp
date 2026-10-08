<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Proposes how to take a wanted quantity from a scope's current package breakdown (ADR 0053 MINIMISE_WASTE, deterministic): the open/loose
 * quantity first, then the fewest whole sealed packages (largest first), then one package opened for the remainder. Read-only: it posts and
 * reserves nothing and never touches cost. The person confirms or edits the result.
 */
final class SuggestPackageDraw
{
    /**
     * @return array{lines:list<array<string,mixed>>, total:string, requires_opening:bool, leaves:array{sealed:list<array<string,mixed>>, loose:string, total:string}}
     */
    public function __invoke(int $itemId, int $locationId, ?int $batchId, string $quantity, User $actor): array
    {
        if (! $actor->hasAnyPermission(['inventory.package-state.issue', 'inventory.package-state.transfer']) || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package issue or transfer, and view, authority in the current company is required.');
        }
        $wanted = BigDecimal::of($quantity)->toScale(4, RoundingMode::UNNECESSARY);
        if ($wanted->isLessThanOrEqualTo(0)) {
            throw new DomainInvariantException('The wanted quantity must be positive.', 'quantity');
        }
        $balances = app(ReadPackageBalances::class)($itemId, $locationId, $batchId, $actor);
        $balance = null;
        foreach ($balances as $row) {
            if ($row['location']['id'] === $locationId && $row['batch_id'] === $batchId) {
                $balance = $row;

                break;
            }
        }
        if ($balance === null || $balance['operation_id'] === null) {
            throw new DomainInvariantException('This location needs a package count before stock can be taken by package.', null, 'NEEDS_COUNT_FIRST');
        }
        if (! $balance['fresh']) {
            throw new DomainInvariantException('The package count for this location is out of date; recount it first.', null, 'STALE_EVIDENCE');
        }
        $total = BigDecimal::of($balance['usable_total']);
        if ($wanted->isGreaterThan($total)) {
            throw new DomainInvariantException("Only {$total->toScale(4)} is on hand here.", 'quantity', 'DRAW_NOT_ON_HAND');
        }

        $loose = BigDecimal::of($balance['loose']);
        $sealed = [];
        foreach ($balance['sealed'] as $row) {
            if ($row['availability'] === 'USABLE') {
                $sealed[] = $row + ['content' => BigDecimal::of($row['content_quantity'])];
            }
        }
        usort($sealed, fn (array $a, array $b): int => $b['content']->compareTo($a['content']));
        $remaining = $wanted;
        $lines = [];
        $takeLoose = $loose->isLessThan($remaining) ? $loose : $remaining;
        if ($takeLoose->isGreaterThan(0)) {
            $lines[] = ['kind' => 'LOOSE', 'quantity' => (string) $takeLoose->toScale(4)];
            $remaining = $remaining->minus($takeLoose);
        }
        $leaves = [];
        foreach ($sealed as $row) {
            $whole = $remaining->dividedBy($row['content'], 0, RoundingMode::DOWN)->toInt();
            $take = min($whole, $row['count']);
            if ($take > 0) {
                $lines[] = ['kind' => 'SEALED', 'packaging_id' => $row['item_packaging_id'], 'name' => $row['name'], 'count' => $take, 'quantity' => (string) $row['content']->multipliedBy($take)->toScale(4)];
                $remaining = $remaining->minus($row['content']->multipliedBy($take));
            }
            $leaves[$row['item_packaging_id']] = ['row' => $row, 'left' => $row['count'] - $take];
        }
        $opened = false;
        $looseLeft = $loose->minus($takeLoose);
        if ($remaining->isGreaterThan(0)) {
            // Open the smallest sealed package that can cover the remainder.
            foreach (array_reverse($sealed) as $row) {
                $left = $leaves[$row['item_packaging_id']]['left'];
                if ($left > 0 && $row['content']->isGreaterThanOrEqualTo($remaining)) {
                    $lines[] = ['kind' => 'OPEN_AND_DRAW', 'packaging_id' => $row['item_packaging_id'], 'name' => $row['name'], 'count' => 1, 'quantity' => (string) $remaining->toScale(4)];
                    $leaves[$row['item_packaging_id']]['left'] = $left - 1;
                    $looseLeft = $looseLeft->plus($row['content']->minus($remaining));
                    $remaining = BigDecimal::zero();
                    $opened = true;

                    break;
                }
            }
        }
        if ($remaining->isGreaterThan(0)) {
            throw new DomainInvariantException('The wanted quantity cannot be made up from the packages on hand; adjust it or recount.', 'quantity', 'DRAW_NOT_ON_HAND');
        }

        return [
            'lines' => $lines, 'total' => (string) $wanted->toScale(4), 'requires_opening' => $opened,
            'leaves' => [
                'sealed' => array_values(array_map(fn (array $entry): array => ['item_packaging_id' => $entry['row']['item_packaging_id'], 'name' => $entry['row']['name'], 'count' => $entry['left'],
                    'content_quantity' => $entry['row']['content_quantity']], $leaves)),
                'loose' => (string) $looseLeft->toScale(4), 'total' => (string) $total->minus($wanted)->toScale(4),
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Inventory\Support\RejectedStockBalance;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Read-only: what a supplier return or scrap of a delivery line's rejected stock would do, before anything is posted (ADR 0097). For each line it states how much is
 * rejected and still on hand, whether the outcome differs from the QA decision (and so needs a reason), which rejected packages would have to leave for a grouped item
 * (with a proposal when whole packages and loose quantity make the quantity exactly), the cost basis, and every blocker with its stable reason code. It posts nothing,
 * reserves nothing and writes no draft.
 */
final class PreviewRejectedStockExit
{
    /**
     * @param  list<array{goods_receipt_line_id:int, quantity?:string|null, outcome_change_reason?:string|null, package_portion?:list<array<string,mixed>>|null}>  $lines
     * @return list<array<string,mixed>>
     */
    public function __invoke(string $kind, array $lines, User $actor): array
    {
        $create = $kind === RejectedStockExit::SUPPLIER_RETURN ? 'purchasing.supplier-return.create' : 'inventory.scrap.create';
        $post = $kind === RejectedStockExit::SUPPLIER_RETURN ? 'purchasing.supplier-return.post' : 'inventory.scrap.post';
        if (! $actor->hasAnyPermission([$create, $post])) {
            throw new DomainInvariantException('The actor is not permitted to prepare this kind of exit.', 'actor');
        }

        return array_map(fn (array $line): array => $this->line($kind, $line, $actor, [$create, $post]), $lines);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $capabilities
     * @return array<string,mixed>
     */
    public function line(string $kind, array $input, User $actor, array $capabilities): array
    {
        $receiptLine = GoodsReceiptLine::query()->findOrFail((int) $input['goods_receipt_line_id']);
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receiptLine->id)->first();
        if ($layer === null) {
            return ['goods_receipt_line_id' => $receiptLine->id, 'blockers' => [$this->blocker('NOTHING_REJECTED_TO_EXIT', 'This delivery line has no stock: it was never posted or has been reversed.')]];
        }
        // A person outside the warehouse's scope learns nothing about it.
        if (! WarehouseScope::permitsLocation($actor, $capabilities, $layer->location_id)) {
            throw new DomainInvariantException('That delivery is outside your warehouses.', 'goods_receipt_line_id');
        }
        // Archived items stay returnable: stock already received must remain processable.
        $item = Item::withTrashed()->findOrFail($receiptLine->item_id);
        $location = Location::query()->findOrFail($layer->location_id);
        $balance = RejectedStockBalance::forLine($receiptLine->id);
        $blockers = [];
        $requested = isset($input['quantity']) && $input['quantity'] !== '' ? $this->quantity((string) $input['quantity']) : null;
        $own = $balance['decided'][$kind]->minus($balance['matched'][$kind]);
        $matching = $own->isNegative() ? BigDecimal::zero() : $own;
        $differing = null;
        if ($requested !== null) {
            $matchingPart = $matching->isLessThan($requested) ? $matching : $requested;
            $differing = $requested->minus($matchingPart);
            if ($requested->isGreaterThan($balance['available'])) {
                $blockers[] = $this->blocker('NOTHING_REJECTED_TO_EXIT', 'Only '.$balance['available']->toScale(4).' of this delivery is rejected and still on hand.');
            }
            if ($differing->isGreaterThan(0) && trim((string) ($input['outcome_change_reason'] ?? '')) === '') {
                $blockers[] = $this->blocker('OUTCOME_CHANGE_REASON_REQUIRED', 'This differs from the QA decision: say why.');
            }
        } elseif ($balance['available']->isLessThanOrEqualTo(0)) {
            $blockers[] = $this->blocker('NOTHING_REJECTED_TO_EXIT', 'Nothing rejected is left to exit from this delivery.');
        }

        $grouped = PackageWriteScope::isAuthoritative($item->id);
        $packages = null;
        $proposed = null;
        $message = null;
        if ($grouped) {
            if (! $actor->can('inventory.package-state.view')) {
                throw new DomainInvariantException('Package-state view authority is required to prepare an exit of package-tracked stock.', 'actor');
            }
            $scope = collect(app(ReadPackageBalances::class)($item->id, $layer->location_id, $layer->batch_id, $actor))
                ->first(fn (array $row): bool => $row['location']['id'] === $layer->location_id && $row['batch_id'] === $layer->batch_id);
            $packages = $scope['rejected'] ?? ['sealed' => [], 'loose' => '0.0000', 'total' => '0.0000'];
            if ($scope !== null && ! $scope['fresh']) {
                $blockers[] = $this->blocker('STALE_EVIDENCE', 'Stock moved since the last package count here: recount this location first.');
            }
            if ($requested !== null) {
                [$proposed, $message] = $this->propose($packages, $requested);
                $declared = $input['package_portion'] ?? null;
                if ($declared === null || $declared === []) {
                    $blockers[] = $this->blocker('NEEDS_DECLARED_PORTION', 'State which rejected sealed packages and loose quantity leave.');
                } else {
                    $problem = $this->coverage($packages, $declared, $requested);
                    if ($problem !== null) {
                        $blockers[] = $this->blocker('DRAW_NOT_ON_HAND', $problem);
                    }
                }
            }
        }

        return [
            'goods_receipt_line_id' => $receiptLine->id, 'item' => ['id' => $item->id, 'code' => $item->code, 'name' => $item->name], 'location_id' => $location->id,
            'warehouse_id' => $location->warehouse_id, 'batch_id' => $layer->batch_id, 'grouped' => $grouped,
            'rejected' => (string) $balance['rejected']->toScale(4), 'exited' => (string) $balance['exited']->toScale(4), 'available' => (string) $balance['available']->toScale(4),
            'decided_for_kind' => (string) $balance['decided'][$kind]->toScale(4), 'requested' => $requested === null ? null : (string) $requested->toScale(4),
            'remaining_after' => $requested === null ? null : (string) $balance['available']->minus($requested)->toScale(4),
            'differing_qty' => $differing === null ? null : (string) $differing->toScale(4), 'outcome_change_required' => $differing !== null && $differing->isGreaterThan(0),
            'rejected_packages' => $packages, 'proposed_portion' => $proposed, 'portion_message' => $message,
            'cost' => $this->cost($item, $layer, $actor), 'blockers' => $blockers,
        ];
    }

    /** @return array{basis:string, unit_cost:string|null} */
    private function cost(Item $item, StockLayer $layer, User $actor): array
    {
        $basis = $item->valuation_method === ValuationMethod::Avco ? 'POOL_AVERAGE' : 'LAYER';
        $visible = $actor->can('inventory.valuation.view');

        return ['basis' => $basis, 'unit_cost' => $visible && $basis === 'LAYER' ? (string) $layer->base_unit_cost : null];
    }

    /**
     * Whole rejected sealed packages (largest first), then rejected loose quantity — only when they add up exactly.
     *
     * @param  array{sealed:list<array<string,mixed>>, loose:string, total:string}  $packages
     * @return array{0:list<array<string,mixed>>|null, 1:string|null}
     */
    private function propose(array $packages, BigDecimal $requested): array
    {
        $remaining = $requested;
        $lines = [];
        $sealed = $packages['sealed'];
        usort($sealed, fn (array $a, array $b): int => BigDecimal::of($b['content_quantity'])->compareTo($a['content_quantity']));
        foreach ($sealed as $row) {
            $content = BigDecimal::of($row['content_quantity']);
            $take = min($remaining->dividedBy($content, 0, RoundingMode::DOWN)->toInt(), $row['count']);
            if ($take > 0) {
                $lines[] = ['kind' => 'SEALED', 'packaging_id' => $row['item_packaging_id'], 'count' => $take];
                $remaining = $remaining->minus($content->multipliedBy($take));
            }
        }
        if ($remaining->isGreaterThan(0) && $remaining->isLessThanOrEqualTo($packages['loose'])) {
            $lines[] = ['kind' => 'LOOSE', 'quantity' => (string) $remaining->toScale(4)];
            $remaining = BigDecimal::zero();
        }
        if ($remaining->isZero() && $lines !== []) {
            return [$lines, null];
        }

        return [null, 'No whole rejected packages and loose quantity add up to '.$requested->toScale(4).': a sealed package leaves whole, so declare the packages this applies to.'];
    }

    /**
     * @param  array{sealed:list<array<string,mixed>>, loose:string, total:string}  $packages
     * @param  list<array<string,mixed>>  $declared
     */
    private function coverage(array $packages, array $declared, BigDecimal $requested): ?string
    {
        $total = BigDecimal::zero();
        foreach ($declared as $row) {
            if (($row['kind'] ?? null) === 'SEALED') {
                $match = collect($packages['sealed'])->firstWhere('item_packaging_id', $row['packaging_id'] ?? null);
                if ($match === null || (int) ($row['count'] ?? 0) > $match['count']) {
                    return 'The declared sealed packages are not all rejected and on hand.';
                }
                $total = $total->plus(BigDecimal::of($match['content_quantity'])->multipliedBy((int) $row['count']));
            } elseif (($row['kind'] ?? null) === 'LOOSE') {
                if (BigDecimal::of((string) ($row['quantity'] ?? '0'))->isGreaterThan($packages['loose'])) {
                    return 'More loose quantity is declared than is rejected.';
                }
                $total = $total->plus((string) $row['quantity']);
            } else {
                return 'A rejected-stock exit removes whole sealed packages and loose quantity only.';
            }
        }

        return $total->isEqualTo($requested) ? null : 'The declared packages must add up to '.$requested->toScale(4).'.';
    }

    private function quantity(string $quantity): BigDecimal
    {
        try {
            $value = BigDecimal::of($quantity);
            if ($value->isGreaterThan(0) && $value->getScale() <= 4) {
                return $value->toScale(4);
            }
        } catch (\Throwable) {
            // domain error below
        }
        throw new DomainInvariantException('The quantity must be positive and no finer than the stock ledger.', 'quantity');
    }

    /** @return array{code:string, message:string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }
}

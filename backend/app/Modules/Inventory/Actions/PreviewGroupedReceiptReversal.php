<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\PackageIssueDraw;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Brick\Math\BigDecimal;

/**
 * Read-only: what reversing one received line of a grouped item would take off the shelf (ADR 0093). It proposes the composition the receipt's own
 * evidence says arrived, shows what is on hand now, says whether that draw is coverable, offers the nearest coverable draw when stock has changed form,
 * and names every blocker the reversal itself would refuse for. It posts nothing and never invents state: a receipt with no evidence has no proposal.
 */
final class PreviewGroupedReceiptReversal
{
    /** @return array<string,mixed> */
    public function __invoke(GoodsReceiptLine $line, User $actor): array
    {
        if (! $actor->can('inventory.package-state.reverse') || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package reverse and view authority is required to reverse a package-tracked receipt.');
        }
        $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->first();
        $blockers = [];
        $observed = $this->observed($line);
        $onHand = null;
        if ($layer !== null) {
            if (! BigDecimal::of($layer->qty_remaining)->isEqualTo($layer->qty_received)) {
                $blockers[] = ['code' => 'LAYER_CONSUMED', 'message' => 'Part of this delivery was already used from stock, so it cannot be reversed. Issue or return the remainder and recount instead.'];
            }
            foreach (app(ReadPackageBalances::class)($line->item_id, $layer->location_id, $layer->batch_id, $actor) as $balance) {
                if ($balance['location']['id'] === $layer->location_id && $balance['batch_id'] === $layer->batch_id) {
                    $onHand = $balance;
                }
            }
        }
        if ($onHand === null || $onHand['operation_id'] === null) {
            $blockers[] = ['code' => 'NEEDS_COUNT_FIRST', 'message' => 'This location has no package count to take the returned packages from: count it first.'];
        } elseif (! $onHand['fresh']) {
            $blockers[] = ['code' => 'STALE_EVIDENCE', 'message' => 'Stock moved since the last package count here: recount this location first.'];
        }

        $quantity = (string) BigDecimal::of($line->stock_qty)->toScale(4);
        $suggested = null;
        $shortfall = [];
        $coverable = false;
        if ($observed === null) {
            $blockers[] = ['code' => 'NEEDS_DECLARED_DRAW', 'message' => 'This receipt predates grouped tracking and has no record of what arrived: state the sealed packages and loose quantity that are going back.'];
        } elseif ($onHand !== null) {
            $shortfall = $this->shortfall($observed, $onHand);
            $coverable = $shortfall === [];
            $suggested = $coverable ? $observed : $this->nearest($observed, $onHand, $quantity);
        }

        return [
            'goods_receipt_line_id' => $line->id, 'item_id' => $line->item_id, 'location_id' => $layer?->location_id, 'batch_id' => $layer?->batch_id, 'quantity' => $quantity,
            'grouped' => true, 'observed' => $observed, 'on_hand' => $onHand === null ? null : [
                'total' => $onHand['total'], 'sealed' => $onHand['sealed'], 'loose' => $onHand['loose'], 'fresh' => $onHand['fresh'],
            ],
            'suggested' => $suggested, 'suggested_changed' => $suggested !== null && ! $coverable, 'coverable' => $coverable, 'shortfall' => $shortfall, 'blockers' => $blockers,
        ];
    }

    /** @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>|null */
    private function observed(GoodsReceiptLine $line): ?array
    {
        $leg = PackageOperation::query()->where('source_receipt_line_id', $line->id)->where('evidence_kind', 'RECEIPT_IN')->first();
        if ($leg === null) {
            return null;
        }
        $lines = [];
        foreach (PackageIssueDraw::query()->where('operation_id', $leg->id)->orderBy('line_no')->get() as $draw) {
            $lines[] = ['kind' => $draw->kind, 'packaging_id' => $draw->item_packaging_id, 'count' => $draw->whole_count, 'quantity' => $draw->kind === 'LOOSE' ? (string) BigDecimal::of($draw->quantity)->toScale(4) : null];
        }

        return $lines;
    }

    /**
     * @param  list<array<string,mixed>>  $draw
     * @param  array<string,mixed>  $onHand
     * @return list<string>
     */
    private function shortfall(array $draw, array $onHand): array
    {
        $messages = [];
        foreach ($draw as $row) {
            if ($row['kind'] === 'SEALED') {
                $have = 0;
                $name = 'package';
                foreach ($onHand['sealed'] as $sealed) {
                    if ($sealed['item_packaging_id'] === $row['packaging_id']) {
                        $have = $sealed['count'];
                        $name = $sealed['name'] ?? $sealed['code'] ?? $name;
                    }
                }
                if ($have < $row['count']) {
                    $messages[] = "{$row['count']} sealed {$name} arrived but only {$have} are on hand now.";
                }
            } elseif (BigDecimal::of($row['quantity'])->isGreaterThan($onHand['loose'])) {
                $messages[] = $this->plain($row['quantity']).' loose arrived but only '.$this->plain($onHand['loose']).' is loose now.';
            }
        }

        return $messages;
    }

    /**
     * The closest draw that is on hand: the arrived sealed packages that are still sealed, the rest from the loose quantity, or null when that cannot add
     * up to the received quantity (the shortfall is then a count or issue problem, never papered over).
     *
     * @param  list<array<string,mixed>>  $observed
     * @param  array<string,mixed>  $onHand
     * @return list<array{kind:string, packaging_id:int|null, count:int|null, quantity:string|null}>|null
     */
    private function nearest(array $observed, array $onHand, string $quantity): ?array
    {
        $remaining = BigDecimal::of($quantity);
        $lines = [];
        foreach ($observed as $row) {
            if ($row['kind'] !== 'SEALED') {
                continue;
            }
            foreach ($onHand['sealed'] as $sealed) {
                if ($sealed['item_packaging_id'] === $row['packaging_id'] && ($take = min($row['count'], $sealed['count'])) > 0) {
                    $lines[] = ['kind' => 'SEALED', 'packaging_id' => $row['packaging_id'], 'count' => $take, 'quantity' => null];
                    $remaining = $remaining->minus(BigDecimal::of($sealed['content_quantity'])->multipliedBy($take));
                }
            }
        }
        if ($remaining->isNegative() || $remaining->isGreaterThan($onHand['loose'])) {
            return null;
        }
        if ($remaining->isGreaterThan(0)) {
            $lines[] = ['kind' => 'LOOSE', 'packaging_id' => null, 'count' => null, 'quantity' => (string) $remaining->toScale(4)];
        }

        return $lines;
    }

    private function plain(string $quantity): string
    {
        return rtrim(rtrim((string) BigDecimal::of($quantity)->toScale(4), '0'), '.');
    }
}

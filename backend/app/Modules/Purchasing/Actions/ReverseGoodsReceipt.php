<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reverses an erroneous posting per ADR 0043: flips the original receipt's
 * status to REVERSED and stamps its reversal metadata directly (the fields
 * already whitelisted post-post by GoodsReceipt's own updating() guard) —
 * it never creates a sibling document or edits posted quantity/cost facts.
 * `reversal_of_id` stays reserved for a separate, not-yet-built feature that
 * links a later ordinary replacement receipt back to this one.
 *
 * Whole-document reversal only: every line is undone in full, or none are.
 */
final class ReverseGoodsReceipt
{
    public function __construct(private readonly PostInventoryMovement $postMovement) {}

    public function __invoke(GoodsReceipt $receipt, string $reason, int $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($receipt, $reason, $actorId): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($receipt->status === GoodsReceiptStatus::Reversed) {
                return $receipt->load('lines');
            }
            if ($receipt->status !== GoodsReceiptStatus::Posted) {
                throw new DomainInvariantException('Only a posted goods receipt can be reversed.', 'status');
            }

            /** @var Collection<int, GoodsReceiptLine> $lines */
            $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->orderBy('id')->lockForUpdate()->get();

            // Purchasing's own downstream fact, checked for every line before any
            // mutation. Layer-consumption and pool-sufficiency are Inventory-owned
            // facts, checked and enforced atomically inside reverseReceipt() below.
            foreach ($lines as $line) {
                if (QualityDisposition::query()->where('goods_receipt_line_id', $line->id)->exists()) {
                    throw new DomainInvariantException('This receipt has a posted QA disposition and cannot be reversed.', 'goods_receipt_line_id');
                }
            }

            // Match PostGoodsReceipt's header-then-line lock order before entering
            // Inventory. Otherwise a concurrent post can hold the PO while waiting
            // for an AVCO pool that this reversal holds while waiting for that PO.
            $poLineIds = $lines->pluck('purchase_order_line_id')->filter()->unique()->sort()->values();
            $orderIds = PurchaseOrderLine::query()->whereKey($poLineIds)->pluck('purchase_order_id')->unique()->sort()->values();
            /** @var Collection<int, PurchaseOrder> $orders */
            $orders = PurchaseOrder::query()->whereKey($orderIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            PurchaseOrderLine::query()->whereKey($poLineIds)->orderBy('id')->lockForUpdate()->get();

            $reversedAt = now();
            foreach ($lines as $line) {
                $this->postMovement->reverseReceipt('GOODS_RECEIPT_LINE', $line->id, 'GOODS_RECEIPT_LINE_REVERSAL', $line->id, $reversedAt);
            }

            $receipt->update([
                'status' => GoodsReceiptStatus::Reversed, 'reversed_at' => $reversedAt,
                'reversed_by' => $actorId, 'correction_reason' => $reason,
            ]);

            // Symmetric to PostGoodsReceipt's own close-when-terminal recompute:
            // a reversal can un-complete a PO that this receipt had closed.
            foreach ($orderIds as $orderId) {
                $order = $orders->get($orderId);
                if (! $order instanceof PurchaseOrder) {
                    throw new DomainInvariantException('Every receipt line must resolve to a visible purchase order.', 'purchase_order_line_id');
                }
                if ($order->status !== PurchaseOrderStatus::Closed) {
                    continue;
                }
                $stillAllTerminal = $order->lines()->get()->every(function (PurchaseOrderLine $poLine): bool {
                    if (BigDecimal::of($poLine->short_closed_stock_qty)->isGreaterThan(0)) {
                        return true;
                    }
                    $posted = GoodsReceiptLine::query()->where('purchase_order_line_id', $poLine->id)
                        ->whereHas('goodsReceipt', fn ($query) => $query->where('status', GoodsReceiptStatus::Posted->value))
                        ->sum('stock_qty');

                    return BigDecimal::of((string) $posted)->isGreaterThanOrEqualTo(BigDecimal::of($poLine->ordered_stock_qty));
                });
                if (! $stillAllTerminal) {
                    $order->update(['status' => PurchaseOrderStatus::Issued, 'closed_at' => null]);
                }
            }

            return $receipt->refresh()->load('lines');
        });
    }
}

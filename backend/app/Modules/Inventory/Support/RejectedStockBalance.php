<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Purchasing\Models\QualityDisposition;
use Brick\Math\BigDecimal;

/**
 * What QA has finally rejected for a receipt line, and how much of it has already left (ADR 0097 §2). Rejected means a terminal RETURN_TO_SUPPLIER or SCRAP
 * disposition; held, pending and released quantity never counts. Pure arithmetic over immutable rows: the cap an exit may not exceed.
 */
final class RejectedStockBalance
{
    /**
     * @return array{rejected:BigDecimal, exited:BigDecimal, available:BigDecimal, decided:array<string,BigDecimal>, matched:array<string,BigDecimal>}
     */
    public static function forLine(int $goodsReceiptLineId): array
    {
        $decided = [RejectedStockExit::SUPPLIER_RETURN => BigDecimal::zero(), RejectedStockExit::SCRAP => BigDecimal::zero()];
        foreach (QualityDisposition::query()->where('goods_receipt_line_id', $goodsReceiptLineId)->whereIn('disposition', ['RETURN_TO_SUPPLIER', 'SCRAP'])->get() as $disposition) {
            $kind = $disposition->disposition->value === 'RETURN_TO_SUPPLIER' ? RejectedStockExit::SUPPLIER_RETURN : RejectedStockExit::SCRAP;
            $decided[$kind] = $decided[$kind]->plus($disposition->qty);
        }
        $matched = [RejectedStockExit::SUPPLIER_RETURN => BigDecimal::zero(), RejectedStockExit::SCRAP => BigDecimal::zero()];
        $exited = BigDecimal::zero();
        foreach (RejectedStockExit::query()->where('goods_receipt_line_id', $goodsReceiptLineId)->get() as $exit) {
            $matched[$exit->kind] = $matched[$exit->kind]->plus($exit->matching_qty);
            $exited = $exited->plus($exit->quantity);
        }
        $rejected = $decided[RejectedStockExit::SUPPLIER_RETURN]->plus($decided[RejectedStockExit::SCRAP]);

        return ['rejected' => $rejected, 'exited' => $exited, 'available' => $rejected->minus($exited), 'decided' => $decided, 'matched' => $matched];
    }

    /** Quantity already exited for the line (all kinds), for availability arithmetic. */
    public static function exitedFor(int $goodsReceiptLineId): BigDecimal
    {
        return BigDecimal::of((string) RejectedStockExit::query()->where('goods_receipt_line_id', $goodsReceiptLineId)->sum('quantity'));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PreviewGroupedReceiptReversal;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\PackageWriteScope;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\QualityDisposition;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use Brick\Math\BigDecimal;

/**
 * Read-only: what reversing a posted goods receipt would do, line by line, and what stands in the way. Grouped lines carry the package preview
 * (proposed draw, what is on hand, blockers); every line reports whether its layer was already used. Nothing is posted.
 */
final class PreviewGoodsReceiptReversal
{
    /** @return array<string,mixed> */
    public function __invoke(GoodsReceipt $receipt, User $actor): array
    {
        if (! $actor->can('purchasing.goods-receipt.reverse')) {
            throw new DomainInvariantException('The actor is not permitted to reverse a goods receipt.', 'reversed_by');
        }
        $blockers = [];
        if ($receipt->status !== GoodsReceiptStatus::Posted) {
            $blockers[] = ['code' => 'NOT_POSTED', 'message' => 'Only a posted goods receipt can be reversed.'];
        }
        $lines = [];
        foreach (GoodsReceiptLine::query()->where('goods_receipt_id', $receipt->id)->orderBy('id')->get() as $line) {
            if (QualityDisposition::query()->where('goods_receipt_line_id', $line->id)->exists()) {
                $blockers[] = ['code' => 'QA_DISPOSITION', 'message' => 'This receipt has a posted QA disposition and cannot be reversed.'];
            }
            if (PackageWriteScope::isAuthoritative($line->item_id)) {
                $lines[] = app(PreviewGroupedReceiptReversal::class)($line, $actor);

                continue;
            }
            $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $line->id)->first();
            $lineBlockers = [];
            if ($layer !== null && ! BigDecimal::of($layer->qty_remaining)->isEqualTo($layer->qty_received)) {
                $lineBlockers[] = ['code' => 'LAYER_CONSUMED', 'message' => 'Part of this delivery was already used from stock, so it cannot be reversed.'];
            }
            $lines[] = ['goods_receipt_line_id' => $line->id, 'item_id' => $line->item_id, 'quantity' => (string) BigDecimal::of($line->stock_qty)->toScale(4), 'grouped' => false, 'blockers' => $lineBlockers];
        }

        return ['goods_receipt_id' => $receipt->id, 'status' => $receipt->status->value, 'blockers' => array_values(array_unique($blockers, SORT_REGULAR)), 'lines' => $lines];
    }
}

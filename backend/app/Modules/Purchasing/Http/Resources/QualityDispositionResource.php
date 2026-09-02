<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Resources;

use App\Modules\Purchasing\Models\QualityDisposition;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QualityDisposition */
class QualityDispositionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'goods_receipt_line_id' => $this->goods_receipt_line_id,
            'source_disposition_id' => $this->source_disposition_id,
            'disposition' => $this->disposition->value,
            'qty' => $this->qty,
            'reason' => $this->reason,
            'disposed_by' => $this->disposed_by,
            'disposed_at' => $this->disposed_at->toIso8601String(),
            'resulting_stock_move_id' => $this->resulting_stock_move_id,
            'remaining_qty' => $this->remainingQty(),
        ];
    }

    /**
     * Relies on the caller having eager-loaded childDispositions's qty sum (see
     * QualityDispositionController) — never queries it directly, so rendering a
     * collection costs one aggregate query total instead of one per row.
     */
    private function remainingQty(): ?string
    {
        if ($this->disposition->isTerminal()) {
            return null;
        }

        return (string) BigDecimal::of($this->qty)->minus(BigDecimal::of((string) ($this->children_qty_sum ?? '0')));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Models\User;
use App\Modules\Inventory\Models\RejectedStockExit;
use App\Modules\Inventory\Models\StockScrapLine;
use App\Modules\Purchasing\Models\SupplierReturnLine;

/**
 * The shared posting step of a Supplier Return and a Scrap document (ADR 0097): each draft line becomes one exit through `PostRejectedStockExit`, in line order,
 * inside the caller's transaction, and the line is linked to its immutable exit record. Retries are safe because every line's request key is derived from the
 * document and line, so a repeated post finds the exits already made. Nothing here is document-specific — the two documents stay separate above this seam.
 */
final class PostRejectedExitLines
{
    public function __construct(private readonly PostRejectedStockExit $exit) {}

    /**
     * @param  iterable<StockScrapLine|SupplierReturnLine>  $lines  draft lines (supplier_return_lines or stock_scrap_lines), in line order
     */
    public function __invoke(string $kind, string $documentType, string $keyPrefix, string $reason, iterable $lines, User $actor): void
    {
        foreach ($lines as $line) {
            $exit = ($this->exit)([
                'kind' => $kind, 'goods_receipt_line_id' => (int) $line->goods_receipt_line_id, 'quantity' => (string) $line->quantity,
                'request_key' => $keyPrefix.':'.$line->id, 'reason' => $reason, 'outcome_change_reason' => $line->outcome_change_reason,
                'portion' => $line->package_portion ?? [], 'document_type' => $documentType, 'document_id' => (int) $line->id,
            ], $actor);
            $line->forceFill(['rejected_stock_exit_id' => $exit->id])->save();
        }
    }

    public static function kindOfSupplierReturn(): string
    {
        return RejectedStockExit::SUPPLIER_RETURN;
    }
}

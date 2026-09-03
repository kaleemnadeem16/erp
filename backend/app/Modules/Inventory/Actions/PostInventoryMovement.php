<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Actions\Movements\IssuePoster;
use App\Modules\Inventory\Actions\Movements\ReceiptPoster;
use App\Modules\Inventory\Actions\Movements\TransferPoster;
use App\Modules\Inventory\Models\StockMove;
use Illuminate\Support\Facades\DB;

/**
 * The sole boundary allowed to create physical and valuation stock state.
 * Receipts, reversals, issues and transfers all post through this Action.
 * The per-movement logic lives in the Movements\ collaborators, which are
 * internal to this boundary — nothing else may call them or write stock
 * state another way. Each public method is one atomic posting.
 */
final class PostInventoryMovement
{
    public function __construct(
        private readonly ReceiptPoster $receipts,
        private readonly IssuePoster $issues,
        private readonly TransferPoster $transfers,
    ) {}

    /**
     * Posts an inbound receipt.
     *
     * amount/base_amount are optional pinned ledger totals. When the caller has an
     * authoritative total that isn't exactly qty × unit_cost — e.g. a UoM-converted
     * receipt, where unit_cost is a rounded per-stock-unit figure and re-deriving the
     * total from it would lose a rounding residual — pass it explicitly here rather
     * than letting it be silently recomputed. Defaults to qty × unit_cost.
     *
     * @param  array{item_id:int, location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, unit_cost:string, currency_id:int, fx_rate:string, base_unit_cost:string, batch_no?:string|null, serial_no?:string|null, amount?:string, base_amount?:string}  $input
     */
    public function __invoke(array $input): StockMove
    {
        return DB::transaction(fn (): StockMove => $this->receipts->post($input));
    }

    /**
     * Posts an outbound issue, optionally consuming an explicit ADR 0037
     * authorisation when exact-location stock is insufficient.
     *
     * @param  array{item_id:int, location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, batch_no?:string|null, serial_no?:string|null, override_request_id?:int|null}  $input
     */
    public function issue(array $input): StockMove
    {
        return DB::transaction(fn (): StockMove => $this->issues->post($input));
    }

    /**
     * Posts a physical location-to-location transfer.
     *
     * @param  array{item_id:int, from_location_id:int, to_location_id:int, qty:string, uom_id:int, moved_at:mixed, source_type:string, source_id:int, batch_no?:string|null, serial_no?:string|null}  $input
     */
    public function transfer(array $input): StockMove
    {
        return DB::transaction(fn (): StockMove => $this->transfers->post($input));
    }

    /**
     * Reverses one specific prior inbound movement by its full originally
     * received quantity — see Movements\ReceiptPoster::reverse() for the
     * exact contract.
     */
    public function reverseReceipt(
        string $originalSourceType,
        int $originalSourceId,
        string $reversalSourceType,
        int $reversalSourceId,
        mixed $reversedAt,
    ): StockMove {
        return DB::transaction(fn (): StockMove => $this->receipts->reverse(
            $originalSourceType, $originalSourceId, $reversalSourceType, $reversalSourceId, $reversedAt,
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Support\DispositionOutcome;
use App\Modules\Purchasing\Support\GoodsReceiptStatus;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The read model behind GET /api/v1/inventory-overview. Five figures per
 * stocked item, computed once via subquery joins rather than N+1 per item —
 * see docs/domain/purchasing.md#L306-313 for the "available" derivation
 * rule this implements for the first time (nothing enforced it before).
 *
 * `on_hand`: ReceiptPoster/IssuePoster maintain a stock_layers row for every
 * item regardless of valuation method (batch/serial identity, FIFO/SPECIFIC
 * consumption ordering) *and*, for AVCO items, keep avco_pools.qty_on_hand in
 * lockstep with the same movements — so an AVCO item has rows in both tables
 * for the same physical quantity. Summing them would double-count; branch on
 * items.valuation_method instead and read only the authoritative source per
 * item (avco_pools for AVCO, stock_layers for FIFO/SPECIFIC).
 *
 * `qa_held`: per goods-receipt-line (has a direct item_id), stock_qty minus
 * whatever's covered by a PASS/ACCEPT_WITH_CONCESSION disposition — the
 * "releases to available" bucket, deliberately not `terminalValues()` (that
 * one wrongly counts SCRAP/RETURN_TO_SUPPLIER as resolved). Only lines whose
 * PO line actually required QA contribute.
 *
 * `on_order`: outstanding qty (ordered − posted-received − short-closed,
 * floored at 0 — mirrors PurchaseOrderLineResource's own formula) summed
 * across ISSUED purchase orders only.
 *
 * `shortage`: open (non-SETTLED) negative_stock_shortages.qty_open.
 *
 * `available`: on_hand − qa_held, floored at 0. Flagged limitation: this
 * doesn't trace whether a SCRAP/RETURN_TO_SUPPLIER-dispositioned quantity
 * already left stock via its own compensating move — correct if write-off
 * posting already reduces on-hand, an over-subtraction until it does.
 *
 * `on_hand_value`/`available_value`: exposed per-row on
 * InventoryOverviewResource, and also selected here so
 * InventoryOverviewController::summary() can SUM them across a filtered,
 * unpaginated copy of this same query for the screen's headline value cards.
 * on_hand_value reads avco_pools.inventory_value directly for AVCO items
 * (already-tracked total cost, not qty × a single unit cost) and
 * SUM(qty_remaining * unit_cost) per layer for FIFO/SPECIFIC. available_value
 * prorates on_hand_value by the available/on_hand qty ratio — this doesn't
 * trace which specific layer or QA lot is held, just applies the item's
 * blended average cost to whatever fraction of on-hand qty is available.
 *
 * Postgres cannot reference one SELECT-list alias from another expression in
 * the same list, so the on_hand_qty/on_hand_value CASE expressions below are
 * repeated verbatim wherever available_qty/available_value need them.
 *
 * Every subquery here uses the raw query builder (`DB::table`), which does
 * not go through Eloquent's BelongsToCompany global scope — each one must
 * filter company_id explicitly, unlike the outer Item::query() base.
 */
final class BuildInventoryOverviewQuery
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @return Builder<Item> */
    public function __invoke(): Builder
    {
        $companyId = $this->companyContext->id();

        $onHandAvco = $this->scoped('avco_pools', $companyId)
            ->selectRaw('item_id, SUM(qty_on_hand) as qty')
            ->groupBy('item_id');

        $onHandLayers = $this->scoped('stock_layers', $companyId)
            ->selectRaw('item_id, SUM(qty_remaining) as qty')
            ->groupBy('item_id');

        $onHandValueAvco = $this->scoped('avco_pools', $companyId)
            ->selectRaw('item_id, SUM(inventory_value) as value')
            ->groupBy('item_id');

        $onHandValueLayers = $this->scoped('stock_layers', $companyId)
            ->selectRaw('item_id, SUM(qty_remaining * unit_cost) as value')
            ->groupBy('item_id');

        $released = $this->scoped('quality_dispositions', $companyId)
            ->whereIn('disposition', array_map(
                fn (DispositionOutcome $outcome): string => $outcome->value,
                array_filter(DispositionOutcome::cases(), fn (DispositionOutcome $outcome): bool => $outcome->releasesStockToAvailable())
            ))
            ->selectRaw('goods_receipt_line_id, SUM(qty) as released_qty')
            ->groupBy('goods_receipt_line_id');

        // Rejected stock that has left for good no longer counts as held (ADR 0097).
        $exited = $this->scoped('rejected_stock_exits', $companyId)
            ->selectRaw('goods_receipt_line_id, SUM(quantity) as exited_qty')
            ->groupBy('goods_receipt_line_id');

        // goods_receipt_lines carries no company_id of its own (scoped only via its
        // parent goods_receipt / purchase_order_line) — company-scope through
        // whichever of those two is already being joined, per query.
        $qaHeld = DB::table('goods_receipt_lines as grl')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'grl.purchase_order_line_id')
            ->where('pol.company_id', $companyId)
            ->leftJoinSub($released, 'released', 'released.goods_receipt_line_id', '=', 'grl.id')
            ->leftJoinSub($exited, 'exited', 'exited.goods_receipt_line_id', '=', 'grl.id')
            ->where('pol.qa_required', true)
            ->selectRaw('grl.item_id, SUM(GREATEST(grl.stock_qty - COALESCE(released.released_qty, 0) - COALESCE(exited.exited_qty, 0), 0)) as qty')
            ->groupBy('grl.item_id');

        $postedReceipts = DB::table('goods_receipt_lines as grl2')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl2.goods_receipt_id')
            ->where('gr.company_id', $companyId)
            ->where('gr.status', GoodsReceiptStatus::Posted->value)
            ->selectRaw('grl2.purchase_order_line_id, SUM(grl2.stock_qty) as received_qty')
            ->groupBy('grl2.purchase_order_line_id');

        $onOrder = $this->scoped('purchase_order_lines as pol2', $companyId, 'pol2.company_id')
            ->join('purchase_orders as po', 'po.id', '=', 'pol2.purchase_order_id')
            ->leftJoinSub($postedReceipts, 'received', 'received.purchase_order_line_id', '=', 'pol2.id')
            ->where('po.status', PurchaseOrderStatus::Issued->value)
            ->selectRaw('pol2.item_id, SUM(GREATEST(pol2.ordered_stock_qty - COALESCE(received.received_qty, 0) - pol2.short_closed_stock_qty, 0)) as qty')
            ->groupBy('pol2.item_id');

        $shortage = $this->scoped('negative_stock_shortages', $companyId)
            ->where('status', '!=', 'SETTLED')
            ->selectRaw('item_id, SUM(qty_open) as qty')
            ->groupBy('item_id');

        $onHandQtyExpr = "CASE WHEN items.valuation_method = 'AVCO' THEN COALESCE(on_hand_avco.qty, 0) ELSE COALESCE(on_hand_layers.qty, 0) END";
        $onHandValueExpr = "CASE WHEN items.valuation_method = 'AVCO' THEN COALESCE(on_hand_value_avco.value, 0) ELSE COALESCE(on_hand_value_layers.value, 0) END";

        return Item::query()
            ->where('is_stocked', true)
            ->leftJoinSub($onHandAvco, 'on_hand_avco', 'on_hand_avco.item_id', '=', 'items.id')
            ->leftJoinSub($onHandLayers, 'on_hand_layers', 'on_hand_layers.item_id', '=', 'items.id')
            ->leftJoinSub($onHandValueAvco, 'on_hand_value_avco', 'on_hand_value_avco.item_id', '=', 'items.id')
            ->leftJoinSub($onHandValueLayers, 'on_hand_value_layers', 'on_hand_value_layers.item_id', '=', 'items.id')
            ->leftJoinSub($qaHeld, 'qa_held', 'qa_held.item_id', '=', 'items.id')
            ->leftJoinSub($onOrder, 'on_order', 'on_order.item_id', '=', 'items.id')
            ->leftJoinSub($shortage, 'shortage', 'shortage.item_id', '=', 'items.id')
            ->select('items.*')
            ->selectRaw("{$onHandQtyExpr} as on_hand_qty")
            ->selectRaw('COALESCE(qa_held.qty, 0) as qa_held_qty')
            ->selectRaw("GREATEST({$onHandQtyExpr} - COALESCE(qa_held.qty, 0), 0) as available_qty")
            ->selectRaw('COALESCE(on_order.qty, 0) as on_order_qty')
            ->selectRaw('COALESCE(shortage.qty, 0) as shortage_qty')
            ->selectRaw("{$onHandValueExpr} as on_hand_value")
            ->selectRaw(
                "CASE WHEN {$onHandQtyExpr} > 0 THEN {$onHandValueExpr} * GREATEST({$onHandQtyExpr} - COALESCE(qa_held.qty, 0), 0) / {$onHandQtyExpr} ELSE 0 END as available_value"
            );
    }

    private function scoped(string $table, int $companyId, string $companyColumn = 'company_id'): QueryBuilder
    {
        return DB::table($table)->where($companyColumn, $companyId);
    }
}

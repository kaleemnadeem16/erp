<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Inventory\Support\InventoryScope;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\StockAvailabilitySnapshot;
use App\Modules\Inventory\Support\StockBalance;
use App\Modules\Purchasing\Support\DispositionOutcome;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The read model behind Estimating's advisory inventory proposal (ADR 0058):
 * every open stock layer of the requested items, grouped by item, location
 * and batch, with QA-held quantity attached. Physical availability only; cost evidence comes from QueryMaterialCost.
 *
 * It is one SQL statement on purpose. A single statement is one consistent
 * snapshot under any isolation level, so quantities, QA holds and pool cost
 * cannot disagree with each other; it takes no lock and posts nothing.
 *
 * Quantity comes from `stock_layers`, never `avco_pools`: layers are the only
 * table carrying a location, and IssuePoster/ReceiptPoster keep them current
 * for AVCO items too (see BuildInventoryOverviewQuery for the same fact seen
 * from the other side).
 *
 * QA hold mirrors BuildInventoryOverviewQuery's rule — a QA-required receipt
 * line holds `stock_qty` minus whatever PASS/ACCEPT_WITH_CONCESSION
 * dispositions released — but attributed to the layer that receipt line
 * created, so the hold lands on the right location instead of only the item
 * total. A layer's hold is capped at what remains in it. Two consequences,
 * both intentional: stock already consumed out of a held layer is not
 * subtracted twice (the overview's known over-subtraction), and a draft
 * receipt, which has no layer yet, holds nothing.
 *
 * Cost is deliberately not read here: a positive-only average cannot tell a genuine zero from absent
 * evidence. Estimating prices the chosen or proposed draw through Inventory's QueryMaterialCost.
 *
 * Every table here is read through the raw query builder, which bypasses
 * BelongsToCompany's global scope, so each one is company-filtered explicitly.
 */
final class QueryStockAvailability
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param list<int> $itemIds */
    public function __invoke(array $itemIds, InventoryScope $scope): StockAvailabilitySnapshot
    {
        $asOf = CarbonImmutable::now();

        if ($itemIds === []) {
            return new StockAvailabilitySnapshot($asOf, []);
        }

        $companyId = $this->companyContext->id();

        $released = DB::table('quality_dispositions')
            ->where('company_id', $companyId)
            ->whereIn('disposition', array_map(
                fn (DispositionOutcome $outcome): string => $outcome->value,
                array_filter(DispositionOutcome::cases(), fn (DispositionOutcome $outcome): bool => $outcome->releasesStockToAvailable()),
            ))
            ->selectRaw('goods_receipt_line_id, SUM(qty) AS released_qty')
            ->groupBy('goods_receipt_line_id');

        // Rejected stock that has left for good no longer counts as held (ADR 0097).
        $exited = DB::table('rejected_stock_exits')
            ->where('company_id', $companyId)
            ->selectRaw('goods_receipt_line_id, SUM(quantity) AS exited_qty')
            ->groupBy('goods_receipt_line_id');

        $held = DB::table('goods_receipt_lines as grl')
            ->join('purchase_order_lines as pol', 'pol.id', '=', 'grl.purchase_order_line_id')
            ->where('pol.company_id', $companyId)
            ->where('pol.qa_required', true)
            ->leftJoinSub($released, 'rel', 'rel.goods_receipt_line_id', '=', 'grl.id')
            ->leftJoinSub($exited, 'ex', 'ex.goods_receipt_line_id', '=', 'grl.id')
            ->selectRaw('grl.id AS grl_id, GREATEST(grl.stock_qty - COALESCE(rel.released_qty, 0) - COALESCE(ex.exited_qty, 0), 0) AS held_qty');

        $query = DB::table('stock_layers as sl')
            ->join('items as it', 'it.id', '=', 'sl.item_id')
            ->join('locations as loc', 'loc.id', '=', 'sl.location_id')
            ->join('warehouses as wh', 'wh.id', '=', 'loc.warehouse_id')
            ->leftJoin('stock_batches as sb', 'sb.id', '=', 'sl.batch_id')
            ->leftJoinSub($held, 'hold', function ($join): void {
                $join->on('hold.grl_id', '=', 'sl.source_id')->where('sl.source_type', 'GOODS_RECEIPT_LINE');
            })
            ->where('sl.company_id', $companyId)
            ->whereIn('sl.item_id', $itemIds)
            ->where('sl.qty_remaining', '>', 0);

        if ($scope->warehouseIds !== []) {
            $query->whereIn('loc.warehouse_id', $scope->warehouseIds);
        }
        if ($scope->locationIds !== []) {
            $query->whereIn('sl.location_id', $scope->locationIds);
        }

        $rows = $query
            ->selectRaw('sl.item_id, sl.location_id, sl.batch_id, sb.code AS batch_code')
            ->selectRaw('loc.warehouse_id, loc.code AS location_code, loc.name AS location_name, loc.type AS location_type, loc.active AS location_active, loc.counts_as_usable AS location_counts_as_usable')
            ->selectRaw('wh.code AS warehouse_code, wh.name AS warehouse_name, wh.active AS warehouse_active')
            ->selectRaw('SUM(sl.qty_remaining) AS on_hand_qty')
            ->selectRaw('SUM(LEAST(sl.qty_remaining, COALESCE(hold.held_qty, 0))) AS held_qty')
            ->groupByRaw('sl.item_id, sl.location_id, sl.batch_id, sb.code, loc.warehouse_id, loc.code, loc.name, loc.type, loc.active, loc.counts_as_usable, wh.code, wh.name, wh.active')
            ->orderByRaw('sl.item_id, wh.code, loc.code, sl.batch_id')
            ->get();

        // stock_layers carries quantities at scale 4; keep every quantity, including a zero, at that scale.
        $zero = BigDecimal::zero()->toScale(4);
        $balances = [];
        foreach ($rows as $row) {
            // SUM(LEAST(qty, COALESCE(held, 0))) comes back as a bare `0` when nothing is held.
            $onHand = BigDecimal::of($row->on_hand_qty)->toScale(4);
            $heldQty = BigDecimal::of($row->held_qty)->toScale(4);
            $free = $onHand->minus($heldQty);
            $locationUsable = (bool) $row->location_active
                && (bool) $row->warehouse_active
                && ($row->location_counts_as_usable ?? LocationType::from($row->location_type)->holdsUsableStock());

            $balances[(int) $row->item_id][] = new StockBalance(
                itemId: (int) $row->item_id,
                warehouseId: (int) $row->warehouse_id,
                warehouseCode: $row->warehouse_code,
                warehouseName: $row->warehouse_name,
                locationId: (int) $row->location_id,
                locationCode: $row->location_code,
                locationName: $row->location_name,
                locationType: LocationType::from($row->location_type),
                locationUsable: $locationUsable,
                batchId: $row->batch_id === null ? null : (int) $row->batch_id,
                batchCode: $row->batch_code,
                onHand: $onHand,
                held: $heldQty,
                unusableLocation: $locationUsable ? $zero : $free,
                usable: $locationUsable ? $free : $zero,
                locationMarked: $row->location_counts_as_usable === null ? null : (bool) $row->location_counts_as_usable,
            );
        }

        return new StockAvailabilitySnapshot($asOf, $balances);
    }
}

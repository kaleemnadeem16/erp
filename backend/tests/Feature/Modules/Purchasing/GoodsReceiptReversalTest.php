<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Actions\PostQualityDisposition;
use App\Modules\Purchasing\Actions\ReverseGoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

class GoodsReceiptReversalTest extends PurchaseOrderFoundationTest
{
    public function test_avco_reversal_credits_the_pool_and_zeroes_the_layer(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'wrong item received'])
            ->assertOk()->assertJsonPath('data.status', 'REVERSED')->assertJsonPath('data.correction_reason', 'wrong item received');

        $this->assertSame('0.0000', AvcoPool::query()->sole()->qty_on_hand);
        $this->assertSame('0.0000', AvcoPool::query()->sole()->inventory_value);
        $this->assertSame('ACTIVE', AvcoPool::query()->sole()->status->value, 'a clean drawdown to zero is not an ADR 0037 negative excursion');
        $this->assertSame('0.0000', StockLayer::query()->sole()->qty_remaining);

        $this->assertDatabaseCount('stock_moves', 2);
        $reversal = StockMove::query()->with('valuationEntry')->where('source_type', 'GOODS_RECEIPT_LINE_REVERSAL')->sole();
        $this->assertNull($reversal->to_location_id);
        $this->assertNotNull($reversal->from_location_id);
        $this->assertSame('-2.0000', $reversal->valuationEntry->qty);
        $this->assertSame('-20.0000', $reversal->valuationEntry->base_amount);
        $this->assertSame('SOURCE_OUT', $reversal->valuationEntry->pool_effect);
    }

    public function test_avco_reversal_preserves_last_valid_average_through_a_zero_drawdown(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $lastValid = AvcoPool::query()->sole()->last_valid_avg_unit_cost;

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])->assertOk();

        $this->assertSame($lastValid, AvcoPool::query()->sole()->last_valid_avg_unit_cost);
        $this->assertSame('0.0000', AvcoPool::query()->sole()->avg_unit_cost);
    }

    public function test_fifo_reversal_zeroes_the_layer_without_touching_a_pool(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])->assertOk();

        $this->assertDatabaseCount('avco_pools', 0);
        $this->assertSame('0.0000', StockLayer::query()->sole()->qty_remaining);
        $reversal = StockMove::query()->with('valuationEntry')->where('source_type', 'GOODS_RECEIPT_LINE_REVERSAL')->sole();
        $this->assertNull($reversal->valuationEntry->avco_pool_id);
        $this->assertNull($reversal->valuationEntry->pool_effect);
        $this->assertSame('-20.0000', $reversal->valuationEntry->amount);
    }

    public function test_reversal_blocked_by_an_existing_qa_disposition(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qaRequired: true);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $lineId = GoodsReceiptLine::query()->where('goods_receipt_id', $receiptId)->sole()->id;
        $this->actingAs(User::factory()->create())->postJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions", [
            'disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok',
        ])->assertCreated();

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])
            ->assertUnprocessable()->assertJsonValidationErrors('goods_receipt_line_id');

        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'status' => 'POSTED']);
        // a blocked reversal must not create any reversing movement
        $this->assertDatabaseCount('stock_moves', 1);
    }

    public function test_reversal_blocked_when_the_layer_has_already_been_partly_consumed(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        // No outbound/issue engine exists yet to legitimately create this state, so
        // it is simulated directly to prove the defensive check that guards against
        // one once it does exist.
        DB::table('stock_layers')->update(['qty_remaining' => '1.0000']);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');

        $this->assertSame('1.0000', StockLayer::query()->sole()->qty_remaining, 'a blocked reversal must not mutate the layer');
        $this->assertDatabaseCount('stock_moves', 1);
    }

    public function test_two_lines_sharing_one_pool_correctly_aggregate_their_combined_requirement(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '6.0000');
        $poLineId = $order->lines()->sole()->id;
        $locationId = $this->locationId();
        $poLine = PurchaseOrderLine::query()->findOrFail($poLineId);
        $lineBase = [
            'purchase_order_line_id' => $poLineId, 'item_id' => $item->id, 'location_id' => $locationId,
            'received_qty' => '3.0000', 'received_uom_id' => $uom->id, 'provisional_unit_cost' => '10.0000',
            'currency_id' => $poLine->purchaseOrder->currency_id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => now()->format('Y-m-d'), 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
        ];
        $receiptId = $this->actingAs(User::factory()->create())->postJson('/api/v1/goods-receipts', [
            'supplier_account_id' => $poLine->purchaseOrder->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [$lineBase, $lineBase],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();
        $pool = AvcoPool::query()->sole();
        $this->assertSame('6.0000', $pool->qty_on_hand);

        // Simulate part of this pool's quantity having left via a mechanism that
        // does not exist yet (no general outbound engine is built). A per-line check
        // made independently against this one frozen snapshot would wrongly pass
        // both 3-unit lines (4 >= 3 individually); only a check against the pool's
        // *current* state after each line's own decrement catches that their
        // combined 6-unit requirement exceeds the 4 actually on hand.
        DB::table('avco_pools')->where('id', $pool->id)->update(['qty_on_hand' => '4.0000']);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_id');

        $this->assertSame('4.0000', $pool->refresh()->qty_on_hand, 'a blocked reversal must not partially apply');
        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'status' => 'POSTED']);
        // no reversing moves for either line of a blocked reversal
        $this->assertDatabaseCount('stock_moves', 2);
    }

    public function test_reversal_is_idempotent(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $first = $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'first'])->assertOk();
        $second = $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'second'])->assertOk();

        $this->assertSame($first->json('data.reversed_at'), $second->json('data.reversed_at'));
        $this->assertSame('first', $second->json('data.correction_reason'), 'a retry must not overwrite the original reversal reason');
        // a retried reversal must not duplicate the reversing movement
        $this->assertDatabaseCount('stock_moves', 2);
        $this->assertDatabaseCount('stock_valuation_entries', 2);
    }

    public function test_only_a_posted_receipt_can_be_reversed(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_reverse_requires_a_reason(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", [])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_reversal_rolls_back_a_po_that_it_had_closed(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'CLOSED']);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'ISSUED']);
    }

    public function test_reversal_reopens_the_po_when_the_remaining_receipt_does_not_cover_the_line(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '4.0000');
        $poLineId = $order->lines()->sole()->id;
        $locationId = $this->locationId();
        $first = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '2.0000']);
        $this->postJson("/api/v1/goods-receipts/{$first}/post")->assertOk();
        $second = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '2.0000']);
        $this->postJson("/api/v1/goods-receipts/{$second}/post")->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'CLOSED']);

        $this->postJson("/api/v1/goods-receipts/{$second}/reverse", ['reason' => 'test'])->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'ISSUED']);

        $this->postJson("/api/v1/goods-receipts/{$first}/reverse", ['reason' => 'test'])->assertOk();
        // Reversing the second receipt already rolled the PO to ISSUED; reversing
        // the first must not error trying to "close" it again from that state.
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'ISSUED']);
    }

    public function test_reversal_locks_purchase_orders_and_lines_before_inventory_state(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $actorId = PurchasingActor::make(['purchasing.goods-receipt.reverse'])->id;

        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(ReverseGoodsReceipt::class)(GoodsReceipt::query()->findOrFail($receiptId), 'test', $actorId);
            $queries = collect(DB::getQueryLog())->pluck('query')->values();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $poHeaderLock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "purchase_orders"') && str_contains($sql, 'for update'));
        $poLineLock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "purchase_order_lines"') && str_contains($sql, 'for update'));
        $layerLock = $queries->search(fn (string $sql): bool => str_contains($sql, 'from "stock_layers"') && str_contains($sql, 'for update'));

        $this->assertIsInt($poHeaderLock);
        $this->assertIsInt($poLineLock);
        $this->assertIsInt($layerLock);
        $this->assertLessThan($poLineLock, $poHeaderLock);
        $this->assertLessThan($layerLock, $poLineLock);
    }

    public function test_a_reversed_receipts_lines_still_reject_new_qa_dispositions(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qaRequired: true);
        $receiptId = $this->postedReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $lineId = GoodsReceiptLine::query()->where('goods_receipt_id', $receiptId)->sole()->id;
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'test'])->assertOk();

        $line = GoodsReceiptLine::query()->findOrFail($lineId);
        $this->expectException(DomainInvariantException::class);
        app(PostQualityDisposition::class)(
            $line,
            ['disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'ok'],
            PurchasingActor::make(['purchasing.quality-disposition.post'])->id,
        );
    }

    private function issuedOrder(ValuationMethod $method, ItemTracking $tracking = ItemTracking::None, string $qty = '2.0000', bool $qaRequired = false): array
    {
        [$order, $item, $uom] = $this->makeOrder();
        $item->update([
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => $item->category->resolveInventoryAccountId(),
        ]);
        if ($item->inventory_account_id === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id]);
        }
        $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => $qty, 'ordered_stock_qty' => $qty, 'line_amount' => (string) ((int) $qty * 10).'.0000',
            'qa_required' => $qaRequired,
        ]));
        app(IssuePurchaseOrder::class)($order, $this->purchasingActor());

        return [$order->refresh(), $item->refresh(), $uom];
    }

    private function locationId(): int
    {
        $warehouse = app(CreateWarehouse::class)([
            'code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main',
        ], PurchasingActor::make(['inventory.warehouse.manage']));

        return $warehouse->locations()->sole()->id;
    }

    private function createReceipt(int $poLineId, int $itemId, int $uomId, int $locationId, array $overrides = []): int
    {
        $poLine = PurchaseOrderLine::query()->findOrFail($poLineId);
        $payload = [
            'supplier_account_id' => $poLine->purchaseOrder->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [array_merge([
                'purchase_order_line_id' => $poLineId, 'item_id' => $itemId, 'location_id' => $locationId,
                'received_qty' => '2.0000', 'received_uom_id' => $uomId, 'provisional_unit_cost' => '10.0000',
                'currency_id' => $poLine->purchaseOrder->currency_id, 'fx_rate' => '1.000000000',
                'fx_rate_date' => now()->format('Y-m-d'), 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            ], $overrides)],
        ];

        return $this->actingAs(User::factory()->create())->postJson('/api/v1/goods-receipts', $payload)
            ->assertCreated()->json('data.id');
    }

    private function postedReceipt(int $poLineId, int $itemId, int $uomId, int $locationId, array $overrides = []): int
    {
        $receiptId = $this->createReceipt($poLineId, $itemId, $uomId, $locationId, $overrides);
        $this->actingAs(User::factory()->create())->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        return $receiptId;
    }
}

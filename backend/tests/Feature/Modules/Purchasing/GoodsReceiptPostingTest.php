<?php

namespace Tests\Feature\Modules\Purchasing;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\OverReceiptMode;
use App\Support\UomDimension;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;

class GoodsReceiptPostingTest extends PurchaseOrderFoundationTest
{
    public function test_receiver_can_create_from_issued_po_without_seeing_or_supplying_cost(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $line = $order->lines()->sole();
        $receiver = PurchasingActor::make(['purchasing.goods-receipt.create']);
        $payload = [
            'supplier_account_id' => $order->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(),
            'posting_date' => now()->format('Y-m-d'),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'item_id' => $item->id,
                'location_id' => $this->locationId(),
                'received_qty' => '2.0000',
                'received_uom_id' => $uom->id,
            ]],
        ];

        $this->actingAsUnprivileged($receiver)
            ->getJson("/api/v1/purchase-orders/{$order->id}?include=lines")
            ->assertOk()->assertJsonMissingPath('data.lines.0.unit_price');
        $this->postJson('/api/v1/goods-receipts', array_replace_recursive($payload, [
            'lines' => [['provisional_unit_cost' => '0.0000']],
        ]))->assertUnprocessable()->assertJsonValidationErrors('lines.0.provisional_unit_cost');

        $created = $this->postJson('/api/v1/goods-receipts', $payload)->assertCreated()
            ->assertJsonMissingPath('data.lines.0.provisional_unit_cost')
            ->assertJsonMissingPath('data.lines.0.base_unit_cost')
            ->assertJsonMissingPath('data.lines.0.fx_rate');
        $receiptId = $created->json('data.id');
        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receiptId,
            'provisional_unit_cost' => $line->unit_price,
            'currency_id' => $order->currency_id,
            'fx_rate' => $order->fx_rate,
        ]);
        $this->getJson("/api/v1/goods-receipts/{$receiptId}")
            ->assertOk()->assertJsonMissingPath('data.lines.0.provisional_unit_cost');
        $this->getJson('/api/v1/goods-receipts?include=lines')
            ->assertOk()->assertJsonMissingPath('data.0.lines.0.provisional_unit_cost');

        PurchasingActor::grant($receiver, ['purchasing.commercial-cost.view']);
        $this->getJson("/api/v1/goods-receipts/{$receiptId}")
            ->assertOk()->assertJsonPath('data.lines.0.provisional_unit_cost', $line->unit_price);
    }

    public function test_receipt_and_qa_reads_and_writes_require_capabilities(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qaRequired: true);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $lineId = GoodsReceiptLine::query()->where('goods_receipt_id', $receiptId)->sole()->id;
        $user = User::factory()->create();

        $this->actingAsUnprivileged($user)->getJson('/api/v1/goods-receipts')->assertForbidden();
        $this->getJson("/api/v1/goods-receipts/{$receiptId}")->assertForbidden();
        $this->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions")->assertForbidden();
        $this->postJson('/api/v1/goods-receipts', [])->assertForbidden();
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertForbidden();
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'Denied'])->assertForbidden();
        $this->postJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions", [])->assertForbidden();

        PurchasingActor::grant($user, ['purchasing.goods-receipt.view']);
        $this->getJson('/api/v1/goods-receipts')->assertOk();
        $this->getJson("/api/v1/goods-receipts/{$receiptId}")->assertOk();
        $this->getJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions")->assertOk();
        $this->postJson('/api/v1/goods-receipts', [])->assertForbidden();
    }

    public function test_avco_receipt_posts_move_layer_feed_and_locked_pool_atomically(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $locationId = $this->locationId();
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")
            ->assertOk()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.number', '0001');

        $move = StockMove::query()->with('valuationEntry')->sole();
        $this->assertSame('2.0000', $move->qty);
        $this->assertSame('20.0000', $move->valuationEntry->base_amount);
        $this->assertSame('2.0000', StockLayer::query()->sole()->qty_remaining);
        $pool = AvcoPool::query()->sole();
        $this->assertSame('2.0000', $pool->qty_on_hand);
        $this->assertSame('20.0000', $pool->inventory_value);
        $this->assertSame('10.0000', $pool->base_avg_unit_cost);
    }

    public function test_avco_pool_uses_weighted_quantity_and_base_value_across_receipts(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '4.0000');
        $poLineId = $order->lines()->sole()->id;
        $locationId = $this->locationId();
        $first = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId);
        $second = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['provisional_unit_cost' => '20.0000']);

        $this->postJson("/api/v1/goods-receipts/{$first}/post")->assertOk();
        $this->postJson("/api/v1/goods-receipts/{$second}/post")->assertOk();

        $pool = AvcoPool::query()->sole();
        $this->assertSame('4.0000', $pool->qty_on_hand);
        $this->assertSame('60.0000', $pool->inventory_value);
        $this->assertSame('15.0000', $pool->base_avg_unit_cost);
    }

    public function test_receipt_in_a_different_uom_rescales_unit_cost_to_the_stock_uom(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '24.0000');
        $box = Uom::create(['code' => 'box', 'name' => 'Box of 12', 'dimension' => UomDimension::Count, 'factor_to_base' => '12']);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $box->id, $this->locationId(), [
            'received_qty' => '2.0000', 'provisional_unit_cost' => '120.0000',
        ]);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $move = StockMove::query()->with('valuationEntry')->sole();
        $this->assertSame('24.0000', $move->qty, 'received qty is converted to the stock UoM basis');
        $this->assertSame('10.0000', $move->unit_cost, 'unit cost is rescaled to the stock UoM basis');
        $this->assertSame('240.0000', $move->valuationEntry->base_amount, 'total value matches what was actually paid');
        $pool = AvcoPool::query()->sole();
        $this->assertSame('10.0000', $pool->avg_unit_cost);
    }

    public function test_avco_receipt_with_a_non_divisible_uom_factor_preserves_the_exact_valuation_total(): void
    {
        // 1 box = 3 stock units, costing 10.0000/box: 3 x (10.0000 / 3 rounded to 3.3333)
        // = 9.9999, silently losing a cent of value. The ledger total must stay 10.0000 —
        // the exact amount actually paid — even though the per-unit cost is necessarily
        // rounded to fit decimal(19,4).
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '3.0000');
        $box = Uom::create(['code' => 'box3', 'name' => 'Box of 3', 'dimension' => UomDimension::Count, 'factor_to_base' => '3']);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $box->id, $this->locationId(), [
            'received_qty' => '1.0000', 'provisional_unit_cost' => '10.0000',
        ]);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $move = StockMove::query()->with('valuationEntry')->sole();
        $this->assertSame('3.0000', $move->qty);
        $this->assertSame('3.3333', $move->unit_cost, 'per-stock-unit cost is rounded for display and future costing');
        $this->assertSame('10.0000', $move->valuationEntry->amount, 'exact total is preserved, not 3 x 3.3333 = 9.9999');
        $this->assertSame('10.0000', $move->valuationEntry->base_amount);

        $layer = StockLayer::query()->sole();
        $this->assertSame('3.3333', $layer->unit_cost);
        $this->assertSame('3.3333', $layer->base_unit_cost);

        $pool = AvcoPool::query()->sole();
        $this->assertSame('3.0000', $pool->qty_on_hand);
        $this->assertSame('10.0000', $pool->inventory_value, 'pool value must not lose the rounding residual');
        $this->assertSame('3.3333', $pool->avg_unit_cost);
    }

    public function test_fifo_receipt_creates_cost_layer_without_avco_pool(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->assertDatabaseCount('avco_pools', 0);
        $this->assertSame('2.0000', StockLayer::query()->sole()->qty_remaining);
        $this->assertNull(StockMove::query()->with('valuationEntry')->sole()->valuationEntry->avco_pool_id);
    }

    public function test_fifo_receipt_with_a_non_divisible_uom_factor_preserves_the_exact_valuation_total(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '3.0000');
        $box = Uom::create(['code' => 'box3f', 'name' => 'Box of 3', 'dimension' => UomDimension::Count, 'factor_to_base' => '3']);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $box->id, $this->locationId(), [
            'received_qty' => '1.0000', 'provisional_unit_cost' => '10.0000',
        ]);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->assertDatabaseCount('avco_pools', 0);
        $move = StockMove::query()->with('valuationEntry')->sole();
        $this->assertSame('3.0000', $move->qty);
        $this->assertSame('3.3333', $move->unit_cost);
        $this->assertSame('10.0000', $move->valuationEntry->amount, 'FIFO keeps the exact total too, not 3 x 3.3333 = 9.9999');
        $this->assertSame('3.3333', StockLayer::query()->sole()->unit_cost);
    }

    public function test_specific_receipt_requires_and_preserves_one_exact_serial(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Specific, ItemTracking::Serial, '1.0000');
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), [
            'received_qty' => '1.0000', 'serial_no' => 'SER-001',
        ]);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->assertSame('SER-001', StockLayer::query()->sole()->serial_no);
        $this->assertSame('SER-001', StockMove::query()->sole()->serial_no);
    }

    public function test_multiple_specific_serials_can_reference_one_po_line_in_the_same_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Specific, ItemTracking::Serial);
        $poLine = $order->lines()->sole();
        $locationId = $this->locationId();
        $base = [
            'purchase_order_line_id' => $poLine->id, 'item_id' => $item->id, 'location_id' => $locationId,
            'received_qty' => '1.0000', 'received_uom_id' => $uom->id, 'provisional_unit_cost' => '10.0000',
            'currency_id' => $order->currency_id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => now()->format('Y-m-d'), 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
        ];
        $payload = [
            'supplier_account_id' => $order->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [array_merge($base, ['serial_no' => 'SER-001']), array_merge($base, ['serial_no' => 'SER-002'])],
        ];
        $receiptId = $this->actingAs(User::factory()->create())->postJson('/api/v1/goods-receipts', $payload)
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->assertDatabaseCount('stock_moves', 2);
        $this->assertSame(['SER-001', 'SER-002'], StockLayer::query()->orderBy('serial_no')->pluck('serial_no')->all());
    }

    public function test_specific_receipt_with_a_fractional_uom_factor_computes_the_correct_exact_total(): void
    {
        // SPECIFIC always posts exactly one stock unit per serialised line, so a
        // non-divisible factor can't be amplified into a visible residual the way it
        // can for AVCO/FIFO (the residual scales with quantity, and quantity here is
        // pinned to 1) — but the same exact-total code path is exercised, and the
        // result must still be mathematically correct: 3 "third" units at 10.0000
        // each is 30.0000 total for the one stock unit they convert to.
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Specific, ItemTracking::Serial, '1.0000');
        $third = Uom::create(['code' => 'third', 'name' => 'Third', 'dimension' => UomDimension::Count, 'factor_to_base' => '0.333333333']);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $third->id, $this->locationId(), [
            'received_qty' => '3.0000', 'provisional_unit_cost' => '10.0000', 'serial_no' => 'SER-001',
        ]);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $move = StockMove::query()->with('valuationEntry')->sole();
        $this->assertSame('1.0000', $move->qty, 'SPECIFIC always posts exactly one stock unit per serial');
        $this->assertSame('30.0000', $move->unit_cost);
        $this->assertSame('30.0000', $move->valuationEntry->amount, 'matches 3 thirds at 10.0000 each = 30.0000 total');
        $layer = StockLayer::query()->sole();
        $this->assertSame('SER-001', $layer->serial_no);
        $this->assertSame('30.0000', $layer->unit_cost);
    }

    public function test_post_retry_is_idempotent(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")
            ->assertOk()->assertJsonPath('data.number', '0001');

        $this->assertDatabaseCount('stock_moves', 1);
        $this->assertDatabaseCount('stock_layers', 1);
        $this->assertDatabaseCount('stock_valuation_entries', 1);
    }

    public function test_partial_receipts_close_the_po_only_when_cumulative_quantity_is_complete(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $poLineId = $order->lines()->sole()->id;
        $locationId = $this->locationId();
        $first = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '1.0000']);
        $this->postJson("/api/v1/goods-receipts/{$first}/post")->assertOk();
        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'ISSUED']);
        // $second is still a DRAFT here (posted below) — its own 1.0000 is now folded
        // into outstanding_stock_qty via draft_held_stock_qty, so outstanding reads
        // 0.0000, not the ordered-minus-posted-only 1.0000 a second draft could
        // otherwise be created against on top of it (the bug scopeWithReceiptProgress's
        // draft_held_stock_qty sum exists to close).
        $second = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '1.0000']);
        $this->getJson("/api/v1/purchase-orders/{$order->id}?include=lines")
            ->assertOk()
            ->assertJsonPath('data.lines.0.received_stock_qty', '1.0000')
            ->assertJsonPath('data.lines.0.outstanding_stock_qty', '0.0000');
        $this->getJson('/api/v1/purchase-orders?filter[status]=ISSUED&include=lines')
            ->assertOk()
            ->assertJsonPath('data.0.lines.0.received_stock_qty', '1.0000')
            ->assertJsonPath('data.0.lines.0.outstanding_stock_qty', '0.0000');

        $this->postJson("/api/v1/goods-receipts/{$second}/post")->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'CLOSED']);
        $this->assertDatabaseCount('stock_moves', 2);
    }

    /**
     * The bug this closes: two separate draft receipts opened against the same
     * PO line each used to see the full ordered quantity as "open" — nothing
     * about creating the first draft reduced what the second's dropdown
     * offered, since outstanding_stock_qty only ever subtracted *posted*
     * receipts. Both would let the user allocate the full quantity, and only
     * the second one's eventual POST would fail, confusingly, against the
     * PO's tolerance ceiling. This proves a single still-unposted draft alone
     * is now enough to bring outstanding_stock_qty to zero.
     */
    public function test_outstanding_quantity_reflects_a_still_unposted_draft_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $poLineId = $order->lines()->sole()->id;

        $this->createReceipt($poLineId, $item->id, $uom->id, $this->locationId(), ['received_qty' => '2.0000']);

        $this->getJson("/api/v1/purchase-orders/{$order->id}?include=lines")
            ->assertOk()
            ->assertJsonPath('data.lines.0.received_stock_qty', '0.0000')
            ->assertJsonPath('data.lines.0.outstanding_stock_qty', '0.0000');
    }

    public function test_cumulative_over_receipt_is_blocked_without_inventory_side_effects(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt(
            $order->lines()->sole()->id,
            $item->id,
            $uom->id,
            $this->locationId(),
            ['received_qty' => '2.0001'],
        );

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")
            ->assertUnprocessable()->assertJsonValidationErrors('stock_qty');

        $this->assertDatabaseCount('stock_moves', 0);
        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'status' => 'DRAFT']);
    }

    public function test_batch_avco_uses_normalised_stable_batch_pool_identity(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, ItemTracking::Batch);
        $item->update(['avco_pool_scope' => AvcoPoolScope::CompanyItemBatch]);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), [
            'batch_no' => ' LOT-01 ',
        ]);

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->assertDatabaseHas('stock_batches', ['item_id' => $item->id, 'code' => 'lot-01']);
        $this->assertSame(AvcoPoolScope::CompanyItemBatch, AvcoPool::query()->sole()->scope);
        $this->assertNotNull(AvcoPool::query()->sole()->batch_id);
    }

    public function test_short_close_after_partial_receipt_records_only_the_remaining_quantity(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $poLine = $order->lines()->sole();
        $receiptId = $this->createReceipt($poLine->id, $item->id, $uom->id, $this->locationId(), [
            'received_qty' => '0.7500',
        ]);
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->postJson("/api/v1/purchase-order-lines/{$poLine->id}/short-close")
            ->assertOk()->assertJsonPath('data.short_closed_stock_qty', '1.2500');

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'CLOSED']);
    }

    public function test_index_filters_by_supplier_and_status_and_paginates(): void
    {
        [$orderA, $itemA, $uomA] = $this->issuedOrder(ValuationMethod::Avco);
        [$orderB, $itemB, $uomB] = $this->issuedOrderB();
        $locationId = $this->locationId();
        $receiptA = $this->createReceipt($orderA->lines()->sole()->id, $itemA->id, $uomA->id, $locationId);
        $receiptB = $this->createReceipt($orderB->lines()->sole()->id, $itemB->id, $uomB->id, $locationId);
        $this->postJson("/api/v1/goods-receipts/{$receiptB}/post")->assertOk();

        $this->getJson('/api/v1/goods-receipts?filter[supplier_account_id]='.$orderA->supplier_account_id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $receiptA);

        $this->getJson('/api/v1/goods-receipts?filter[status]=POSTED')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $receiptB);

        $this->getJson('/api/v1/goods-receipts?filter[search]=0001')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $receiptB);

        $this->getJson('/api/v1/goods-receipts?sort=unknown')->assertUnprocessable();
    }

    /**
     * Phase 2 integrity audit: the model's own "posted goods-receipt facts are
     * immutable" guard is mirrored by goods_receipts_guard/goods_receipt_lines_guard
     * in the database, so raw SQL that bypasses Eloquent still cannot rewrite or
     * delete posted history — while a draft receipt stays fully deletable.
     */
    public function test_posted_receipt_and_its_lines_are_database_immutable(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '4.0000');
        $locationId = $this->locationId();
        $poLineId = $order->lines()->sole()->id;
        $posted = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId);
        $draft = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId);
        $this->postJson("/api/v1/goods-receipts/{$posted}/post")->assertOk();
        $lineId = (int) DB::table('goods_receipt_lines')->where('goods_receipt_id', $posted)->value('id');

        $this->assertDatabaseRejects(fn () => DB::table('goods_receipts')->where('id', $posted)->update(['supplier_account_id' => $order->supplier_account_id + 1]));
        $this->assertDatabaseRejects(fn () => DB::table('goods_receipt_lines')->where('id', $lineId)->update(['received_qty' => '99.0000']));
        $this->assertDatabaseRejects(fn () => DB::table('goods_receipt_lines')->where('id', $lineId)->delete());
        $this->assertDatabaseRejects(fn () => DB::table('goods_receipts')->where('id', $posted)->delete());

        DB::table('goods_receipts')->where('id', $draft)->delete();
        $this->assertDatabaseMissing('goods_receipts', ['id' => $draft]);
        $this->assertDatabaseHas('goods_receipts', ['id' => $posted, 'status' => 'POSTED']);
    }

    private function assertDatabaseRejects(callable $mutation): void
    {
        try {
            DB::transaction($mutation);
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());

            return;
        }

        $this->fail('Expected the database to reject the mutation.');
    }

    /**
     * ADR 0043's ALLOW_WITHIN_TOLERANCE arm had no posting test at all — only
     * the BLOCK arm above was covered, so neither the tolerance ceiling nor
     * its precision-based flooring was ever exercised.
     */
    private function tolerantOrder(string $orderedQty, string $tolerancePercent, ?string $quantityPrecision = null): array
    {
        [$order, $item, $uom] = $this->makeOrder();
        $item->update([
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'avco_pool_scope' => AvcoPoolScope::CompanyItem, 'quantity_precision' => $quantityPrecision,
            'inventory_account_id' => $item->category->resolveInventoryAccountId() ?? ChartOfAccount::query()->firstOrFail()->id,
        ]);
        $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => $orderedQty, 'ordered_stock_qty' => $orderedQty, 'line_amount' => '100.0000',
            'over_receipt_mode' => OverReceiptMode::AllowWithinTolerance,
            'over_receipt_tolerance_percent' => $tolerancePercent,
        ]));
        app(IssuePurchaseOrder::class)($order, $this->purchasingActor());

        return [$order->refresh(), $item->refresh(), $uom];
    }

    public function test_a_receipt_at_exactly_the_tolerance_ceiling_posts(): void
    {
        // 10 ordered + 20% = a ceiling of exactly 12.
        [$order, $item, $uom] = $this->tolerantOrder('10.0000', '20.0000');
        $receiptId = $this->createReceipt(
            $order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), ['received_qty' => '12.0000'],
        );

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();
        $this->assertDatabaseCount('stock_moves', 1);
    }

    public function test_a_receipt_one_step_above_the_tolerance_ceiling_is_rejected(): void
    {
        [$order, $item, $uom] = $this->tolerantOrder('10.0000', '20.0000');
        $receiptId = $this->createReceipt(
            $order->lines()->sole()->id, $item->id, $uom->id, $this->locationId(), ['received_qty' => '12.0001'],
        );

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")
            ->assertUnprocessable()->assertJsonValidationErrors('stock_qty');
        $this->assertDatabaseCount('stock_moves', 0);
    }

    public function test_the_tolerance_ceiling_floors_to_the_items_explicit_quantity_precision(): void
    {
        // 10 + 15% is 11.5; an explicit quantity_precision of 1 floors the
        // ceiling to 11 rather than rounding to 12 — an item(-and-UoM) rule
        // (ADR 0050), never inferred from the stock UoM's COUNT dimension.
        [$order, $item, $uom] = $this->tolerantOrder('10.0000', '15.0000', '1');
        $locationId = $this->locationId();

        $rejected = $this->createReceipt(
            $order->lines()->sole()->id, $item->id, $uom->id, $locationId, ['received_qty' => '12.0000'],
        );
        $this->postJson("/api/v1/goods-receipts/{$rejected}/post")
            ->assertUnprocessable()->assertJsonValidationErrors('stock_qty');

        $accepted = $this->createReceipt(
            $order->lines()->sole()->id, $item->id, $uom->id, $locationId, ['received_qty' => '11.0000'],
        );
        $this->postJson("/api/v1/goods-receipts/{$accepted}/post")->assertOk();
    }

    public function test_the_tolerance_ceiling_stays_fractional_for_a_countable_item_with_no_quantity_precision_set(): void
    {
        // Same 10 + 15% = 11.5 ceiling as above, but with no explicit
        // quantity_precision — divisibility is never inferred from the stock
        // UoM's COUNT dimension (ADR 0050), so the ceiling is not floored and
        // a fractional receipt right at 11.5 is accepted.
        [$order, $item, $uom] = $this->tolerantOrder('10.0000', '15.0000');
        $locationId = $this->locationId();

        $accepted = $this->createReceipt(
            $order->lines()->sole()->id, $item->id, $uom->id, $locationId, ['received_qty' => '11.5000'],
        );
        $this->postJson("/api/v1/goods-receipts/{$accepted}/post")->assertOk();
    }

    public function test_cumulative_partial_receipts_are_measured_against_the_same_ceiling(): void
    {
        [$order, $item, $uom] = $this->tolerantOrder('10.0000', '20.0000');
        $poLineId = $order->lines()->sole()->id;
        $locationId = $this->locationId();

        $first = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '9.0000']);
        $this->postJson("/api/v1/goods-receipts/{$first}/post")->assertOk();

        // 9 + 3 = 12 is exactly the ceiling; 9 + 4 would exceed it.
        $tooMuch = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '4.0000']);
        $this->postJson("/api/v1/goods-receipts/{$tooMuch}/post")
            ->assertUnprocessable()->assertJsonValidationErrors('stock_qty');

        $exact = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '3.0000']);
        $this->postJson("/api/v1/goods-receipts/{$exact}/post")->assertOk();
    }

    public function test_item_valuation_policy_is_locked_after_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->patchJson("/api/v1/items/{$item->id}", ['valuation_method' => 'FIFO'])
            ->assertUnprocessable()->assertJsonValidationErrors('valuation_method');
    }

    /**
     * The same lock covers all three fields, but only valuation_method had a
     * test — so a regression freeing either of the other two would have passed.
     */
    public function test_item_stock_uom_is_locked_after_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $otherUom = Uom::create(['code' => 'box-lock', 'name' => 'Box', 'dimension' => UomDimension::Count, 'factor_to_base' => '12']);

        $this->patchJson("/api/v1/items/{$item->id}", ['stock_uom_id' => $otherUom->id])
            ->assertUnprocessable()->assertJsonValidationErrors('stock_uom_id');
    }

    public function test_item_avco_pool_scope_is_locked_after_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->patchJson("/api/v1/items/{$item->id}", ['avco_pool_scope' => 'WAREHOUSE_ITEM'])
            ->assertUnprocessable()->assertJsonValidationErrors('avco_pool_scope');
    }

    /**
     * Unlike the other three, tracking wasn't locked at all until this test was
     * added — StockIdentity's validate*Tracking()/findOnHandBatch() key batch/
     * serial identity off the item's *current* tracking mode, so a change after
     * stock exists strands the pre-change on-hand quantity with no way to
     * acquire the identity a later mode requires.
     */
    public function test_item_tracking_is_locked_after_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, ItemTracking::None);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->patchJson("/api/v1/items/{$item->id}", ['tracking' => 'BATCH'])
            ->assertUnprocessable()->assertJsonValidationErrors('tracking');
    }

    public function test_failure_rolls_back_every_inventory_effect_and_receipt_status(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $item->update(['inventory_account_id' => null]);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")
            ->assertUnprocessable()->assertJsonValidationErrors('item_id');

        $this->assertDatabaseCount('stock_moves', 0);
        $this->assertDatabaseCount('stock_layers', 0);
        $this->assertDatabaseCount('avco_pools', 0);
        $this->assertDatabaseHas('goods_receipts', ['id' => $receiptId, 'status' => 'DRAFT']);
    }

    /**
     * A second, independently-coded order/item/uom/supplier — issuedOrder() reuses
     * PurchaseOrderFoundationTest::makeOrder()'s hardcoded 'SUP'/'ea'/'raw'/'item'
     * codes, which collide on a second call within the same test.
     *
     * @return array{PurchaseOrder, Item, Uom}
     */
    private function issuedOrderB(ValuationMethod $method = ValuationMethod::Avco, string $qty = '2.0000'): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        $party = Party::create(['code' => 'SUP2', 'name' => 'SUP2']);
        $account = ChartOfAccount::create(['code' => 'pay-SUP2', 'name' => 'Payable SUP2', 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => 'SUP2', 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);
        $uom = Uom::create(['code' => 'ea2', 'name' => 'Each 2', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw2', 'name' => 'Raw 2', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'item2', 'name' => 'Item 2', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();

        $item->update([
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => ItemTracking::None,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => $item->category->resolveInventoryAccountId(),
        ]);
        if ($item->inventory_account_id === null) {
            $item->update(['inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id]);
        }
        $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => $qty, 'ordered_stock_qty' => $qty, 'line_amount' => (string) ((int) $qty * 10).'.0000',
        ]));
        app(IssuePurchaseOrder::class)($order, $this->purchasingActor());

        return [$order->refresh(), $item->refresh(), $uom];
    }

    private function issuedOrder(
        ValuationMethod $method,
        ItemTracking $tracking = ItemTracking::None,
        string $qty = '2.0000',
        bool $qaRequired = false,
    ): array {
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
        ]);

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
}

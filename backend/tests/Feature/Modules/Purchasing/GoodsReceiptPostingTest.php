<?php

namespace Tests\Feature\Modules\Purchasing;

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
use App\Modules\Purchasing\Models\PurchaseOrderLine;

class GoodsReceiptPostingTest extends PurchaseOrderFoundationTest
{
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

    public function test_fifo_receipt_creates_cost_layer_without_avco_pool(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());

        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->assertDatabaseCount('avco_pools', 0);
        $this->assertSame('2.0000', StockLayer::query()->sole()->qty_remaining);
        $this->assertNull(StockMove::query()->with('valuationEntry')->sole()->valuationEntry->avco_pool_id);
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

        $second = $this->createReceipt($poLineId, $item->id, $uom->id, $locationId, ['received_qty' => '1.0000']);
        $this->postJson("/api/v1/goods-receipts/{$second}/post")->assertOk();

        $this->assertDatabaseHas('purchase_orders', ['id' => $order->id, 'status' => 'CLOSED']);
        $this->assertDatabaseCount('stock_moves', 2);
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

    public function test_item_valuation_policy_is_locked_after_receipt(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $receiptId = $this->createReceipt($order->lines()->sole()->id, $item->id, $uom->id, $this->locationId());
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $this->patchJson("/api/v1/items/{$item->id}", ['valuation_method' => 'FIFO'])
            ->assertUnprocessable()->assertJsonValidationErrors('valuation_method');
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

    private function issuedOrder(ValuationMethod $method, ItemTracking $tracking = ItemTracking::None, string $qty = '2.0000'): array
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
        ]));
        app(IssuePurchaseOrder::class)($order);

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

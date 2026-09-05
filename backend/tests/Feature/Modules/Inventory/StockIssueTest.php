<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\DecideNegativeStockOverride;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\Recalculation\LayerCounterfactualReplayer;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use Brick\Math\BigDecimal;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Modules\Purchasing\PurchaseOrderFoundationTest;

class StockIssueTest extends PurchaseOrderFoundationTest
{
    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.negative-stock.request', 'inventory.negative-stock.approve'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    public function test_avco_issue_uses_the_locked_average_and_reduces_pool_and_physical_layer(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '4.0000', '10.0000');

        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '1.5000', 1001));

        $this->assertSame('1.5000', $move->qty);
        $this->assertSame('10.0000', $move->unit_cost);
        $this->assertSame('2.5000', StockLayer::query()->sole()->qty_remaining);
        $this->assertSame('2.5000', AvcoPool::query()->sole()->qty_on_hand);
        $this->assertSame('25.0000', AvcoPool::query()->sole()->inventory_value);
        $this->assertSame('-15.0000', $move->valuationEntries()->sole()->base_amount);
        $this->assertSame('SOURCE_OUT', $move->valuationEntries()->sole()->pool_effect);
    }

    public function test_full_avco_drawdown_consumes_the_exact_pool_value_without_a_rounding_residual(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '3.0000');
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '3.0000', '10.0000');
        AvcoPool::query()->sole()->update([
            'inventory_value' => '10.0000', 'avg_unit_cost' => '3.3333',
            'base_avg_unit_cost' => '3.3333', 'last_valid_avg_unit_cost' => '3.3333',
        ]);

        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '3.0000', 1008));

        $this->assertSame('0.0000', AvcoPool::query()->sole()->qty_on_hand);
        $this->assertSame('0.0000', AvcoPool::query()->sole()->inventory_value);
        $this->assertSame('-10.0000', $move->valuationEntries()->sole()->base_amount);
    }

    public function test_fifo_issue_consumes_oldest_layers_and_preserves_each_cost_component(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '5.0000');
        $locationId = $this->locationId();
        $poLineId = $order->lines()->sole()->id;
        $this->postReceipt($poLineId, $item->id, $uom->id, $locationId, '2.0000', '10.0000');
        $this->travel(1)->second();
        $this->postReceipt($poLineId, $item->id, $uom->id, $locationId, '3.0000', '12.0000');

        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '3.0000', 1002));

        $this->assertSame(['0.0000', '2.0000'], StockLayer::query()->orderBy('received_at')->orderBy('id')->pluck('qty_remaining')->all());
        $this->assertSame(['-20.0000', '-12.0000'], $move->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertSame('10.6667', $move->unit_cost);
    }

    public function test_specific_issue_requires_and_consumes_the_exact_serial(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Specific, ItemTracking::Serial);
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '1.0000', '25.0000', serialNo: 'SER-1');

        $move = app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationId, '1.0000', 1003),
            ['serial_no' => 'SER-1'],
        ));

        $this->assertSame('SER-1', $move->serial_no);
        $this->assertSame('0.0000', StockLayer::query()->sole()->qty_remaining);
        $this->assertSame('-25.0000', $move->valuationEntries()->sole()->base_amount);
    }

    public function test_insufficient_location_stock_blocks_without_side_effects(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo);
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '2.0000', '10.0000');

        try {
            app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '3.0000', 1004));
            $this->fail('Expected insufficient stock to be rejected.');
        } catch (DomainInvariantException) {
            $this->assertSame('2.0000', StockLayer::query()->sole()->qty_remaining);
            $this->assertDatabaseCount('stock_moves', 1);
            $this->assertDatabaseCount('stock_valuation_entries', 1);
        }
    }

    public function test_qa_pending_and_failed_quantity_is_not_available_to_issue(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '4.0000', qaRequired: true);
        $locationId = $this->locationId();
        $receiptId = $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '4.0000', '10.0000');
        $lineId = GoodsReceiptLine::query()->where('goods_receipt_id', $receiptId)->sole()->id;
        $user = User::factory()->create();
        $this->actingAs($user)->postJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions", [
            'disposition' => 'PASS', 'qty' => '2.0000', 'reason' => 'accepted',
        ])->assertCreated();
        $this->postJson("/api/v1/goods-receipt-lines/{$lineId}/quality-dispositions", [
            'disposition' => 'SCRAP', 'qty' => '1.0000', 'reason' => 'failed',
        ])->assertCreated();

        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '2.0000', 1005));
        $this->expectException(DomainInvariantException::class);
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '0.0001', 1006));
    }

    public function test_issue_retry_is_idempotent(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '2.0000', '10.0000');
        $input = $this->issueInput($item->id, $uom->id, $locationId, '1.0000', 1007);

        $first = app(PostInventoryMovement::class)->issue($input);
        $second = app(PostInventoryMovement::class)->issue($input);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('1.0000', StockLayer::query()->sole()->qty_remaining);
        $this->assertDatabaseCount('stock_moves', 2);
    }

    public function test_approved_fifo_override_posts_supported_and_provisional_cost_components(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '2.0000');
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '2.0000', '10.0000');
        $request = $this->approvedOverride($item->id, $locationId, '5.0000', '2.0000', 1101);

        $move = app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationId, '5.0000', 1101),
            ['override_request_id' => $request->id],
        ));

        $shortage = NegativeStockShortage::query()->sole();
        $this->assertSame('3.0000', $shortage->qty_open);
        $this->assertSame('10.0000', $shortage->provisional_unit_cost);
        $this->assertSame($request->id, $shortage->override_request_id);
        $this->assertSame(['-20.0000', '-30.0000'], $move->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertSame('0.0000', StockLayer::query()->sole()->qty_remaining);
    }

    public function test_override_blocks_when_live_shortage_is_worse_than_approved(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '1.0000');
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '1.0000', '10.0000');
        $request = $this->approvedOverride($item->id, $locationId, '5.0000', '2.0000', 1102);

        $this->expectException(DomainInvariantException::class);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationId, '5.0000', 1102),
            ['override_request_id' => $request->id],
        ));
    }

    public function test_prior_override_cannot_post_before_approval(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '1.0000');
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '1.0000', '10.0000');
        $request = $this->requestOverride($item->id, $locationId, '2.0000', '1.0000', 1105);

        $this->expectException(DomainInvariantException::class);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationId, '2.0000', 1105),
            ['override_request_id' => $request->id],
        ));
    }

    public function test_avco_override_uses_last_valid_average_and_freezes_negative_pool(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '2.0000');
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '2.0000', '10.0000');
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationId, '2.0000', 1103));
        $request = $this->approvedOverride($item->id, $locationId, '1.0000', '0.0000', 1104);

        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationId, '1.0000', 1104),
            ['override_request_id' => $request->id],
        ));

        $pool = AvcoPool::query()->sole();
        $this->assertSame('-1.0000', $pool->qty_on_hand);
        $this->assertSame('-10.0000', $pool->inventory_value);
        $this->assertSame('10.0000', $pool->last_valid_avg_unit_cost);
        $this->assertSame('FROZEN', $pool->status->value);

        $receipt = $this->directReceipt($item->id, $uom->id, $locationId, $order->currency_id, '2.0000', '12.0000', 1201);
        $pool->refresh();
        $this->assertSame('1.0000', $pool->qty_on_hand);
        $this->assertSame('12.0000', $pool->inventory_value);
        $this->assertSame('12.0000', $pool->base_avg_unit_cost);
        $this->assertSame('ACTIVE', $pool->status->value);
        $this->assertSame(['24.0000', '-2.0000'], $receipt->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertSame('1.0000', StockLayer::query()->where('source_id', 1201)->sole()->qty_remaining);
        $this->assertSame('SETTLED', NegativeStockShortage::query()->sole()->status);
    }

    public function test_fifo_inbound_partially_then_fully_settles_shortage_and_only_excess_becomes_available(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '2.0000');
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '2.0000', '10.0000');
        $request = $this->approvedOverride($item->id, $locationId, '5.0000', '2.0000', 1202);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationId, '5.0000', 1202),
            ['override_request_id' => $request->id],
        ));

        $partial = $this->directReceipt($item->id, $uom->id, $locationId, $order->currency_id, '2.0000', '12.0000', 1203);
        $shortage = NegativeStockShortage::query()->sole();
        $this->assertSame('1.0000', $shortage->qty_open);
        $this->assertSame('PARTIALLY_SETTLED', $shortage->status);
        $this->assertSame('0.0000', StockLayer::query()->where('source_id', 1203)->sole()->qty_remaining);
        $this->assertSame(['24.0000', '-4.0000'], $partial->valuationEntries()->orderBy('id')->pluck('base_amount')->all());

        $final = $this->directReceipt($item->id, $uom->id, $locationId, $order->currency_id, '2.0000', '14.0000', 1204);
        $shortage->refresh();
        $this->assertSame('0.0000', $shortage->qty_open);
        $this->assertSame('SETTLED', $shortage->status);
        $this->assertNotNull($shortage->settled_at);
        $this->assertSame('1.0000', StockLayer::query()->where('source_id', 1204)->sole()->qty_remaining);
        $this->assertSame(['28.0000', '-4.0000'], $final->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertSame(['2.0000', '1.0000'], $shortage->settlements()->orderBy('id')->pluck('qty_settled')->all());
    }

    public function test_same_pool_avco_transfer_moves_physical_stock_without_changing_pool_value(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $sourceId = $this->locationId();
        $destinationId = $this->otherLocationId('other');
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $sourceId, '4.0000', '10.0000');
        $before = AvcoPool::query()->sole();

        $move = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $sourceId, $destinationId, '1.5000', 1301));

        $before->refresh();
        $this->assertSame('4.0000', $before->qty_on_hand);
        $this->assertSame('40.0000', $before->inventory_value);
        $this->assertSame(['-15.0000', '15.0000'], $move->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertSame($move->transferDetail->source_avco_pool_id, $move->transferDetail->destination_avco_pool_id);
        $this->assertSame('1.5000', StockLayer::query()->where('location_id', $destinationId)->sole()->qty_remaining);
    }

    public function test_cross_pool_avco_transfer_conserves_company_quantity_and_value(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '4.0000');
        $item->update(['avco_pool_scope' => AvcoPoolScope::WarehouseItem]);
        $sourceId = $this->locationId();
        $destinationId = $this->otherLocationId('other');
        $lineId = $order->lines()->sole()->id;
        $this->postReceipt($lineId, $item->id, $uom->id, $sourceId, '2.0000', '10.0000');
        $this->postReceipt($lineId, $item->id, $uom->id, $destinationId, '2.0000', '20.0000');

        $move = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $sourceId, $destinationId, '1.0000', 1302));
        $pools = AvcoPool::query()->orderBy('warehouse_id')->get();

        $this->assertEquals(4, $pools->sum('qty_on_hand'));
        $this->assertEquals(60, $pools->sum('inventory_value'));
        $sourcePool = $pools->firstWhere('warehouse_id', Location::query()->findOrFail($sourceId)->warehouse_id);
        $destinationPool = $pools->firstWhere('warehouse_id', Location::query()->findOrFail($destinationId)->warehouse_id);
        $this->assertSame('1.0000', $sourcePool->qty_on_hand);
        $this->assertSame('10.0000', $sourcePool->inventory_value);
        $this->assertSame('3.0000', $destinationPool->qty_on_hand);
        $this->assertSame('50.0000', $destinationPool->inventory_value);
        $this->assertSame('16.6667', $destinationPool->base_avg_unit_cost);
        $this->assertNotSame($move->transferDetail->source_avco_pool_id, $move->transferDetail->destination_avco_pool_id);
    }

    public function test_fifo_transfer_preserves_each_source_cost_layer_and_company_value(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '5.0000');
        $sourceId = $this->locationId();
        $destinationId = $this->otherLocationId('other');
        $lineId = $order->lines()->sole()->id;
        $this->postReceipt($lineId, $item->id, $uom->id, $sourceId, '2.0000', '10.0000');
        $this->travel(1)->second();
        $this->postReceipt($lineId, $item->id, $uom->id, $sourceId, '3.0000', '12.0000');

        $move = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $sourceId, $destinationId, '3.0000', 1303));

        $destinationLayers = StockLayer::query()->where('location_id', $destinationId)->orderBy('source_line_no')->get();
        $this->assertSame(['2.0000', '1.0000'], $destinationLayers->pluck('qty_remaining')->all());
        $this->assertSame(['10.0000', '12.0000'], $destinationLayers->pluck('base_unit_cost')->all());
        $sourceLayers = StockLayer::query()->where('location_id', $sourceId)->orderBy('received_at')->get();
        $this->assertSame(
            $sourceLayers->map(fn (StockLayer $layer): string => (string) $layer->received_at)->all(),
            $destinationLayers->map(fn (StockLayer $layer): string => (string) $layer->received_at)->all(),
            'A transfer must preserve each source layer\'s original receipt time — FIFO age belongs to the stock, not its custody path.',
        );
        $this->assertSame(['-20.0000', '20.0000', '-12.0000', '12.0000'], $move->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertNull($move->transferDetail->source_avco_pool_id);
        $this->assertNull($move->transferDetail->destination_avco_pool_id);
    }

    public function test_transfer_from_a_frozen_pool_keeps_its_source_rate_after_an_unrelated_shortage_settles(): void
    {
        // ADR 0045: a transfer's exact source location is always fully
        // physically backed, so a non-positive wider pool never makes the
        // transfer itself provisional — it only reflects unrelated shortage
        // debt elsewhere in the same pool. The transfer's rate is final at
        // post time and is never later repaired, matching the treatment a
        // physically-covered issue from the same frozen pool already gets.
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '10.0000');
        $item->update(['avco_pool_scope' => AvcoPoolScope::WarehouseItem]);
        $warehouse = app(CreateWarehouse::class)(['code' => 'w1', 'name' => 'W1', 'location_code' => 'a', 'location_name' => 'A']);
        $locationAId = $warehouse->locations()->sole()->id;
        $locationBId = $warehouse->locations()->create(['code' => 'b', 'name' => 'B'])->id;
        $locationCId = $this->otherLocationId('c');
        $lineId = $order->lines()->sole()->id;
        $this->postReceipt($lineId, $item->id, $uom->id, $locationAId, '6.0000', '10.0000');
        $this->postReceipt($lineId, $item->id, $uom->id, $locationBId, '4.0000', '10.0000');

        $request = $this->approvedOverride($item->id, $locationAId, '15.0000', '6.0000', 1401);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationAId, '15.0000', 1401),
            ['override_request_id' => $request->id],
        ));
        $pool = AvcoPool::query()->sole();
        $this->assertSame('-5.0000', $pool->qty_on_hand);
        $this->assertSame('FROZEN', $pool->status->value);

        // B's own physical stock (4 units, untouched by A's shortage) fully
        // covers this transfer — it is never provisional.
        $transfer = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $locationBId, $locationCId, '3.0000', 1402));
        $destinationPool = AvcoPool::query()->where('id', '!=', $pool->id)->sole();
        $this->assertSame('3.0000', $destinationPool->qty_on_hand);
        $this->assertSame('30.0000', $destinationPool->inventory_value);
        $this->assertSame('10.0000', $transfer->unit_cost);

        // Settles the shortage at A — an unrelated location to the transfer.
        $this->directReceipt($item->id, $uom->id, $locationAId, $order->currency_id, '15.0000', '12.0000', 1403);

        $pool->refresh();
        $destinationPool->refresh();
        $this->assertSame('7.0000', $pool->qty_on_hand);
        $this->assertSame('82.0000', $pool->inventory_value);
        // The destination pool — and the transfer that fed it — are
        // completely unaffected by the later settlement at a $12 rate.
        $this->assertSame('3.0000', $destinationPool->qty_on_hand);
        $this->assertSame('30.0000', $destinationPool->inventory_value);
        $this->assertSame('10.0000', $destinationPool->base_avg_unit_cost);
        $this->assertSame(
            ['-30.0000', '30.0000'],
            $transfer->valuationEntries()->orderBy('id')->pluck('base_amount')->all(),
        );
    }

    public function test_recalculation_refuses_current_qa_totals_as_historical_availability(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qaRequired: true);
        $locationId = $this->locationId();
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationId, '4.0000', '10.0000');
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('Historical QA availability');
        app(LayerCounterfactualReplayer::class)->replay(
            $item, Location::query()->findOrFail($locationId), null, null,
            BigDecimal::of('1'), BigDecimal::of('10'), now()->subDay(),
        );
    }

    protected function issuedOrder(ValuationMethod $method, ItemTracking $tracking = ItemTracking::None, string $qty = '4.0000', bool $qaRequired = false): array
    {
        [$order, $item, $uom] = $this->makeOrder();
        Company::query()->findOrFail(1)->update(['base_currency_id' => $order->currency_id]);
        $item->update([
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id,
        ]);
        $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => $qty, 'ordered_stock_qty' => $qty,
            'line_amount' => (string) ((int) $qty * 10).'.0000', 'qa_required' => $qaRequired,
        ]));
        app(IssuePurchaseOrder::class)($order);

        return [$order->refresh(), $item->refresh(), $uom];
    }

    protected function locationId(): int
    {
        return app(CreateWarehouse::class)([
            'code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main',
        ])->locations()->sole()->id;
    }

    protected function otherLocationId(string $code): int
    {
        return app(CreateWarehouse::class)([
            'code' => $code, 'name' => ucfirst($code), 'location_code' => $code, 'location_name' => ucfirst($code),
        ])->locations()->sole()->id;
    }

    protected function postReceipt(int $poLineId, int $itemId, int $uomId, int $locationId, string $qty, string $cost, ?string $serialNo = null): int
    {
        $poLine = PurchaseOrderLine::query()->findOrFail($poLineId);
        $receiptId = $this->actingAs(User::factory()->create())->postJson('/api/v1/goods-receipts', [
            'supplier_account_id' => $poLine->purchaseOrder->supplier_account_id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [[
                'purchase_order_line_id' => $poLineId, 'item_id' => $itemId, 'location_id' => $locationId,
                'received_qty' => $qty, 'received_uom_id' => $uomId, 'provisional_unit_cost' => $cost,
                'currency_id' => $poLine->purchaseOrder->currency_id, 'fx_rate' => '1.000000000',
                'fx_rate_date' => now()->format('Y-m-d'), 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
                'serial_no' => $serialNo,
            ]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        return $receiptId;
    }

    protected function issueInput(int $itemId, int $uomId, int $locationId, string $qty, int $sourceId): array
    {
        return [
            'item_id' => $itemId, 'location_id' => $locationId, 'qty' => $qty, 'uom_id' => $uomId,
            'moved_at' => now(), 'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId,
        ];
    }

    protected function transferInput(int $itemId, int $uomId, int $sourceId, int $destinationId, string $qty, int $referenceId): array
    {
        return [
            'item_id' => $itemId, 'from_location_id' => $sourceId, 'to_location_id' => $destinationId,
            'qty' => $qty, 'uom_id' => $uomId, 'moved_at' => now(),
            'source_type' => 'TEST_TRANSFER', 'source_id' => $referenceId,
        ];
    }

    protected function approvedOverride(int $itemId, int $locationId, string $requested, string $available, int $sourceId): NegativeStockOverrideRequest
    {
        $request = $this->requestOverride($itemId, $locationId, $requested, $available, $sourceId);
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.negative-stock.approve');
        app(DecideNegativeStockOverride::class)($request, NegativeStockApprovalDecision::Approved, 'Evidence checked', $approver);

        return $request;
    }

    protected function requestOverride(int $itemId, int $locationId, string $requested, string $available, int $sourceId): NegativeStockOverrideRequest
    {
        Company::query()->findOrFail(1)->update([
            'negative_stock_mode' => 'AUTHORISED_OVERRIDE',
            'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL',
        ]);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');

        return app(RequestNegativeStockOverride::class)([
            'item_id' => $itemId, 'location_id' => $locationId, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => $requested, 'available_qty' => $available,
            'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Receipt paperwork is delayed',
        ], $requester);
    }

    protected function directReceipt(int $itemId, int $uomId, int $locationId, int $currencyId, string $qty, string $cost, int $sourceId): mixed
    {
        return app(PostInventoryMovement::class)([
            'item_id' => $itemId, 'location_id' => $locationId, 'qty' => $qty, 'uom_id' => $uomId,
            'moved_at' => now(), 'source_type' => 'DIRECT_RECEIPT', 'source_id' => $sourceId,
            'unit_cost' => $cost, 'currency_id' => $currencyId, 'fx_rate' => '1.000000000',
            'base_unit_cost' => $cost,
        ]);
    }
}

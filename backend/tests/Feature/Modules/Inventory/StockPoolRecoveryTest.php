<?php

namespace Tests\Feature\Modules\Inventory;

use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Models\StockTransferRepair;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;

/**
 * Pool-level recovery behaviour around ADR 0037: frozen pools must never
 * block genuinely available physical stock, and repairs must conserve
 * company value across every relaying pool.
 */
class StockPoolRecoveryTest extends StockIssueTest
{
    public function test_receipt_at_a_non_shortage_location_of_a_frozen_pool_posts_and_blends(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco);
        $locationAId = $this->locationId();
        $locationBId = $this->otherLocationId('other');
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationAId, '2.0000', '10.0000');
        $request = $this->approvedOverride($item->id, $locationAId, '4.0000', '2.0000', 2101);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationAId, '4.0000', 2101),
            ['override_request_id' => $request->id],
        ));
        $pool = AvcoPool::query()->sole();
        $this->assertSame('FROZEN', $pool->status->value);

        $receipt = $this->directReceipt($item->id, $uom->id, $locationBId, $order->currency_id, '5.0000', '12.0000', 2102);

        $pool->refresh();
        $this->assertSame('3.0000', $pool->qty_on_hand);
        $this->assertSame('40.0000', $pool->inventory_value);
        $this->assertSame('ACTIVE', $pool->status->value);
        $this->assertSame(['60.0000'], $receipt->valuationEntries()->pluck('base_amount')->all());
        $this->assertSame('5.0000', StockLayer::query()->where('source_id', 2102)->sole()->qty_remaining);
        $shortage = NegativeStockShortage::query()->sole();
        $this->assertSame('OPEN', $shortage->status);
        $this->assertSame('2.0000', $shortage->qty_open);

        $this->directReceipt($item->id, $uom->id, $locationAId, $order->currency_id, '2.0000', '14.0000', 2103);
        $pool->refresh();
        $shortage->refresh();
        $this->assertSame('5.0000', $pool->qty_on_hand);
        $this->assertSame('60.0000', $pool->inventory_value);
        $this->assertSame('SETTLED', $shortage->status);
        $this->assertSame('0.0000', StockLayer::query()->where('source_id', 2103)->sole()->qty_remaining);
    }

    public function test_physically_available_stock_issues_while_the_wider_pool_cannot_support_it(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '5.0000');
        $locationAId = $this->locationId();
        $locationBId = $this->otherLocationId('other');
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationAId, '5.0000', '10.0000');
        $request = $this->approvedOverride($item->id, $locationBId, '4.0000', '0.0000', 2201);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationBId, '4.0000', 2201),
            ['override_request_id' => $request->id],
        ));
        $pool = AvcoPool::query()->sole();
        $this->assertSame('1.0000', $pool->qty_on_hand);

        $move = app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationAId, '3.0000', 2202));

        $pool->refresh();
        $this->assertSame('10.0000', $move->unit_cost);
        $this->assertSame('2.0000', StockLayer::query()->where('location_id', $locationAId)->sole()->qty_remaining);
        $this->assertSame('-2.0000', $pool->qty_on_hand);
        $this->assertSame('-20.0000', $pool->inventory_value);
        $this->assertSame('FROZEN', $pool->status->value);
        $this->assertSame(['-30.0000'], $move->valuationEntries()->pluck('base_amount')->all());
        $this->assertSame(1, NegativeStockShortage::query()->count());
    }

    public function test_a_transfer_settles_a_destination_shortage_at_the_exact_incoming_layer_cost(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '8.0000');
        $locationAId = $this->locationId();
        $locationBId = $this->otherLocationId('other');
        $lineId = $order->lines()->sole()->id;
        $this->postReceipt($lineId, $item->id, $uom->id, $locationBId, '1.0000', '9.0000');
        app(PostInventoryMovement::class)->issue($this->issueInput($item->id, $uom->id, $locationBId, '1.0000', 2401));
        $this->travel(1)->second();
        $this->postReceipt($lineId, $item->id, $uom->id, $locationAId, '2.0000', '10.0000');
        $this->travel(1)->second();
        $this->postReceipt($lineId, $item->id, $uom->id, $locationAId, '3.0000', '12.0000');
        $request = $this->approvedOverride($item->id, $locationBId, '2.0000', '0.0000', 2402);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationBId, '2.0000', 2402),
            ['override_request_id' => $request->id],
        ));

        $move = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $locationAId, $locationBId, '4.0000', 2403));

        $shortage = NegativeStockShortage::query()->sole();
        $this->assertSame('SETTLED', $shortage->status);
        $settlement = $shortage->settlements()->sole();
        $this->assertSame('9.0000', $settlement->provisional_unit_cost);
        $this->assertSame('10.0000', $settlement->actual_unit_cost);
        $this->assertSame('1.0000', $settlement->unit_cost_difference);
        $this->assertSame('-2.0000', $settlement->valuation_adjustment);
        $this->assertSame(
            ['0.0000', '2.0000'],
            StockLayer::query()->where('source_id', 2403)->orderBy('source_line_no')->pluck('qty_remaining')->all(),
        );
        $this->assertSame(
            ['-20.0000', '20.0000', '-24.0000', '24.0000', '-2.0000'],
            $move->valuationEntries()->orderBy('id')->pluck('base_amount')->all(),
        );
    }

    public function test_repair_propagation_cascades_through_a_relaying_pool(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Avco, qty: '10.0000');
        $item->update(['avco_pool_scope' => AvcoPoolScope::WarehouseItem]);
        $warehouseA = app(CreateWarehouse::class)(['code' => 'wa', 'name' => 'WA', 'location_code' => 'a1', 'location_name' => 'A1']);
        $locationA1Id = $warehouseA->locations()->sole()->id;
        $locationA2Id = $warehouseA->locations()->create(['code' => 'a2', 'name' => 'A2'])->id;
        $warehouseB = app(CreateWarehouse::class)(['code' => 'wb', 'name' => 'WB', 'location_code' => 'b1', 'location_name' => 'B1']);
        $locationB1Id = $warehouseB->locations()->sole()->id;
        $locationB2Id = $warehouseB->locations()->create(['code' => 'b2', 'name' => 'B2'])->id;
        $locationC1Id = $this->otherLocationId('wc');
        $this->postReceipt($order->lines()->sole()->id, $item->id, $uom->id, $locationA1Id, '10.0000', '10.0000');

        $requestA = $this->approvedOverride($item->id, $locationA2Id, '10.0000', '0.0000', 2501);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationA2Id, '10.0000', 2501),
            ['override_request_id' => $requestA->id],
        ));
        $first = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $locationA1Id, $locationB1Id, '6.0000', 2502));
        $this->assertTrue($first->transferDetail->is_provisional);
        $requestB = $this->approvedOverride($item->id, $locationB2Id, '8.0000', '0.0000', 2503);
        app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationB2Id, '8.0000', 2503),
            ['override_request_id' => $requestB->id],
        ));
        $second = app(PostInventoryMovement::class)->transfer($this->transferInput($item->id, $uom->id, $locationB1Id, $locationC1Id, '5.0000', 2504));
        $this->assertTrue($second->transferDetail->is_provisional);

        $this->directReceipt($item->id, $uom->id, $locationA2Id, $order->currency_id, '12.0000', '16.0000', 2505);

        $poolA = AvcoPool::query()->where('warehouse_id', $warehouseA->id)->sole();
        $poolB = AvcoPool::query()->where('warehouse_id', $warehouseB->id)->sole();
        $poolC = AvcoPool::query()->whereNotIn('warehouse_id', [$warehouseA->id, $warehouseB->id])->sole();
        $this->assertSame('6.0000', $poolA->qty_on_hand);
        $this->assertSame('36.0000', $poolA->inventory_value);
        $this->assertSame('-7.0000', $poolB->qty_on_hand);
        $this->assertSame('-64.0000', $poolB->inventory_value);
        $this->assertSame('FROZEN', $poolB->status->value);
        $this->assertSame('5.0000', $poolC->qty_on_hand);
        $this->assertSame('80.0000', $poolC->inventory_value);
        $this->assertSame('16.0000', $poolC->base_avg_unit_cost);
        $repairs = StockTransferRepair::query()->orderBy('stock_transfer_detail_id')->get();
        $this->assertSame(
            [$first->transferDetail->id, $second->transferDetail->id],
            $repairs->pluck('stock_transfer_detail_id')->all(),
        );
        $this->assertSame(['16.0000', '16.0000'], $repairs->pluck('actual_unit_cost')->all());
        $this->assertSame(['36.0000', '30.0000'], $repairs->pluck('valuation_adjustment')->all());
        // The two repairs reallocate between pools without touching company
        // totals: physical 6 + (-7 open) + 5 ... valued 36 - 64 + 80 = 52.
        $this->assertEquals(52, AvcoPool::query()->sum('inventory_value'));
        $this->assertSame('OPEN', NegativeStockShortage::query()->where('location_id', $locationB2Id)->sole()->status);
    }

    public function test_recovered_stock_posts_normally_and_leaves_the_override_unused(): void
    {
        [$order, $item, $uom] = $this->issuedOrder(ValuationMethod::Fifo, qty: '4.0000');
        $locationAId = $this->locationId();
        $lineId = $order->lines()->sole()->id;
        $this->postReceipt($lineId, $item->id, $uom->id, $locationAId, '2.0000', '10.0000');
        $request = $this->approvedOverride($item->id, $locationAId, '3.0000', '2.0000', 2601);
        $this->travel(1)->second();
        $this->postReceipt($lineId, $item->id, $uom->id, $locationAId, '2.0000', '11.0000');

        $move = app(PostInventoryMovement::class)->issue(array_merge(
            $this->issueInput($item->id, $uom->id, $locationAId, '3.0000', 2601),
            ['override_request_id' => $request->id],
        ));

        $this->assertSame(0, NegativeStockShortage::query()->count());
        $this->assertSame(['-20.0000', '-11.0000'], $move->valuationEntries()->orderBy('id')->pluck('base_amount')->all());
        $this->assertSame(
            ['0.0000', '1.0000'],
            StockLayer::query()->orderBy('received_at')->orderBy('id')->pluck('qty_remaining')->all(),
        );
    }
}

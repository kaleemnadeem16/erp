<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\QueryStockAvailability;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\InventoryScope;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Inventory\Support\StockAvailabilitySnapshot;
use App\Modules\Inventory\Support\StockBalance;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * The read model behind Estimating's inventory proposal. The risk is the SQL:
 * per-location grouping, the QA-hold attribution to a layer, and the three-way
 * AVCO pool join — so every case posts real stock through PostInventoryMovement
 * (or a real posted, QA-required goods receipt) and reads it back.
 */
class QueryStockAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Uom $uom;

    private Category $category;

    private ChartOfAccount $inventoryAccount;

    private int $sourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $this->uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $this->category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->inventoryAccount = ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]);
    }

    public function test_open_layers_are_summed_per_location_and_the_scope_only_narrows(): void
    {
        $item = $this->item('paint');
        $mainA = $this->warehouse('a');
        $binA = Location::create(['warehouse_id' => $mainA->warehouse_id, 'code' => 'bin2', 'name' => 'Bin 2']);
        $mainB = $this->warehouse('b');
        $this->receive($item, $mainA, '3', '10.0000');
        $this->receive($item, $mainA, '2', '10.0000');
        $this->receive($item, $binA, '4', '10.0000');
        $this->receive($item, $mainB, '5', '10.0000');
        app(PostInventoryMovement::class)->issue($this->issueInput($item, $mainA, '1'));

        // Ordered by warehouse code, then location code — a stable order, not insertion order.
        $all = $this->snapshot([$item->id]);
        $this->assertSame(
            ['a/bin2' => '4.0000', 'a/main' => '4.0000', 'b/main' => '5.0000'],
            $this->usableByLocation($all->balancesFor($item->id)),
        );

        $warehouseA = $this->snapshot([$item->id], new InventoryScope(warehouseIds: [$mainA->warehouse_id]));
        $this->assertSame(['a/bin2' => '4.0000', 'a/main' => '4.0000'], $this->usableByLocation($warehouseA->balancesFor($item->id)));

        $oneLocation = $this->snapshot([$item->id], new InventoryScope(locationIds: [$binA->id]));
        $this->assertSame(['a/bin2' => '4.0000'], $this->usableByLocation($oneLocation->balancesFor($item->id)));

        // Both filters given: the intersection, never the union.
        $none = $this->snapshot([$item->id], new InventoryScope(warehouseIds: [$mainB->warehouse_id], locationIds: [$binA->id]));
        $this->assertSame([], $none->balancesFor($item->id));
    }

    public function test_a_fully_consumed_layer_and_an_unknown_item_contribute_nothing(): void
    {
        $item = $this->item('paint');
        $main = $this->warehouse('a');
        $this->receive($item, $main, '2', '10.0000');
        app(PostInventoryMovement::class)->issue($this->issueInput($item, $main, '2'));

        $this->assertSame([], $this->snapshot([$item->id])->balancesFor($item->id));
        $this->assertSame([], $this->snapshot([])->balancesByItem);
        $this->assertSame([], $this->snapshot([999999])->balancesFor(999999));
    }

    public function test_stock_in_a_location_that_cannot_hold_usable_stock_is_reported_but_not_counted(): void
    {
        $item = $this->item('paint');
        $main = $this->warehouse('a');
        $quarantine = Location::create(['warehouse_id' => $main->warehouse_id, 'code' => 'quar', 'name' => 'Quarantine', 'type' => LocationType::Quarantine]);
        $retired = Location::create(['warehouse_id' => $main->warehouse_id, 'code' => 'old', 'name' => 'Old bin']);
        $this->receive($item, $main, '5', '10.0000');
        $this->receive($item, $quarantine, '4', '10.0000');
        $this->receive($item, $retired, '3', '10.0000');
        $retired->update(['active' => false]);

        $byLocation = collect($this->snapshot([$item->id])->balancesFor($item->id))->keyBy(fn (StockBalance $b): string => $b->locationCode);

        $this->assertSame('5.0000', (string) $byLocation['main']->usable);
        $this->assertTrue($byLocation['main']->locationUsable);

        $this->assertSame('4.0000', (string) $byLocation['quar']->onHand);
        $this->assertSame('0.0000', (string) $byLocation['quar']->usable);
        $this->assertSame('4.0000', (string) $byLocation['quar']->unusableLocation);
        $this->assertFalse($byLocation['quar']->locationUsable);

        $this->assertSame('0.0000', (string) $byLocation['old']->usable);
        $this->assertFalse($byLocation['old']->locationUsable);
    }

    public function test_a_locations_own_mark_overrides_what_its_type_implies_in_both_directions(): void
    {
        $item = $this->item('paint');
        $main = $this->warehouse('a');
        $returns = Location::create(['warehouse_id' => $main->warehouse_id, 'code' => 'ret', 'name' => 'Returns', 'type' => LocationType::Returns]);
        $overflow = Location::create(['warehouse_id' => $main->warehouse_id, 'code' => 'over', 'name' => 'Overflow']);
        $quarantine = Location::create(['warehouse_id' => $main->warehouse_id, 'code' => 'quar', 'name' => 'Quarantine', 'type' => LocationType::Quarantine]);
        foreach ([$main, $returns, $overflow, $quarantine] as $location) {
            $this->receive($item, $location, '5', '10.0000');
        }
        // Good stock comes back through Returns; the overflow shelf holds damaged goods; quarantine is left to its type.
        $returns->update(['counts_as_usable' => true]);
        $overflow->update(['counts_as_usable' => false]);

        $byLocation = collect($this->snapshot([$item->id])->balancesFor($item->id))->keyBy(fn (StockBalance $b): string => $b->locationCode);

        $this->assertSame('5.0000', (string) $byLocation['ret']->usable);
        $this->assertTrue($byLocation['ret']->locationUsable);
        $this->assertTrue($byLocation['ret']->locationMarked);
        $this->assertSame('0.0000', (string) $byLocation['over']->usable);
        $this->assertSame('5.0000', (string) $byLocation['over']->unusableLocation);
        $this->assertFalse($byLocation['over']->locationMarked);
        $this->assertFalse($byLocation['quar']->locationUsable);
        $this->assertNull($byLocation['quar']->locationMarked);
        $this->assertNull($byLocation['main']->locationMarked);
    }

    public function test_a_mark_never_makes_an_inactive_location_usable(): void
    {
        $item = $this->item('paint');
        $main = $this->warehouse('a');
        $old = Location::create(['warehouse_id' => $main->warehouse_id, 'code' => 'old', 'name' => 'Old bin']);
        $this->receive($item, $main, '5', '10.0000');
        $this->receive($item, $old, '3', '10.0000');
        $old->update(['counts_as_usable' => true]);
        $old->update(['active' => false]);

        $byLocation = collect($this->snapshot([$item->id])->balancesFor($item->id))->keyBy(fn (StockBalance $b): string => $b->locationCode);

        $this->assertFalse($byLocation['old']->locationUsable);
        $this->assertSame('0.0000', (string) $byLocation['old']->usable);
    }

    public function test_avco_cost_follows_the_items_pool_scope(): void
    {
        $company = $this->item('company-pool', AvcoPoolScope::CompanyItem);
        $perWarehouse = $this->item('warehouse-pool', AvcoPoolScope::WarehouseItem);
        $a = $this->warehouse('a');
        $b = $this->warehouse('b');
        foreach ([$company, $perWarehouse] as $item) {
            $this->receive($item, $a, '2', '10.0000');
            $this->receive($item, $b, '2', '20.0000');
        }

        // One company-wide pool: 4 units worth 60 → 15.0000 everywhere.
        $this->assertSame(
            ['a/main' => '15.0000', 'b/main' => '15.0000'],
            $this->costByLocation($this->snapshot([$company->id])->balancesFor($company->id)),
        );
        // A pool per warehouse: each location keeps its own cost.
        $this->assertSame(
            ['a/main' => '10.0000', 'b/main' => '20.0000'],
            $this->costByLocation($this->snapshot([$perWarehouse->id])->balancesFor($perWarehouse->id)),
        );
        $this->assertSame('AVCO_POOL', $this->snapshot([$company->id])->balancesFor($company->id)[0]->costMethod);
    }

    public function test_batch_valued_avco_splits_one_location_into_one_balance_per_batch_with_its_own_cost(): void
    {
        $item = $this->item('batched', AvcoPoolScope::CompanyItemBatch, tracking: 'BATCH');
        $main = $this->warehouse('a');
        $this->receive($item, $main, '2', '10.0000', batch: 'b1');
        $this->receive($item, $main, '3', '30.0000', batch: 'b2');

        $balances = collect($this->snapshot([$item->id])->balancesFor($item->id))->keyBy(fn (StockBalance $b): string => (string) $b->batchCode);

        $this->assertSame(['b1', 'b2'], $balances->keys()->all());
        $this->assertSame('10.0000', (string) $balances['b1']->unitCost);
        $this->assertSame('30.0000', (string) $balances['b2']->unitCost);
        $this->assertSame('3.0000', (string) $balances['b2']->usable);
    }

    public function test_fifo_cost_is_the_weighted_average_of_the_remaining_layers(): void
    {
        $item = $this->item('fifo', valuation: ValuationMethod::Fifo);
        $main = $this->warehouse('a');
        $this->receive($item, $main, '2', '10.0000');
        $this->travel(1)->second();
        $this->receive($item, $main, '2', '12.0000');
        $this->travel(1)->second();
        // Consumes the oldest layer first, leaving 2 @ 12.
        app(PostInventoryMovement::class)->issue($this->issueInput($item, $main, '2'));

        $balance = $this->snapshot([$item->id])->balancesFor($item->id)[0];

        $this->assertSame('2.0000', (string) $balance->onHand);
        $this->assertSame('12.0000', (string) $balance->unitCost);
        $this->assertSame('LAYER_WEIGHTED_AVERAGE', $balance->costMethod);
    }

    public function test_cost_is_null_never_zero_without_defensible_evidence(): void
    {
        $frozen = $this->item('frozen');
        $free = $this->item('free');
        $main = $this->warehouse('a');
        $this->receive($frozen, $main, '2', '10.0000');
        $this->receive($free, $main, '2', '0.0000');
        AvcoPool::query()->where('item_id', $frozen->id)->update(['status' => 'FROZEN']);

        $this->assertNull($this->snapshot([$frozen->id])->balancesFor($frozen->id)[0]->unitCost);
        $this->assertNull($this->snapshot([$free->id])->balancesFor($free->id)[0]->unitCost);
    }

    public function test_qa_held_stock_lands_on_the_receiving_location_and_reconciles_with_the_inventory_overview(): void
    {
        $item = $this->item('qa-item');
        $receiving = $this->warehouse('a');
        $other = $this->warehouse('b');
        $this->receiveThroughQaRequiredPurchaseOrder($item, $receiving, received: '6.0000', passed: '4.0000');
        $this->receive($item, $other, '3', '10.0000');

        $balances = collect($this->snapshot([$item->id])->balancesFor($item->id))->keyBy(fn (StockBalance $b): string => $b->warehouseCode);

        // 6 received, 4 released by a PASS disposition → 2 still held, only at the QA location.
        $this->assertSame('6.0000', (string) $balances['a']->onHand);
        $this->assertSame('2.0000', (string) $balances['a']->held);
        $this->assertSame('4.0000', (string) $balances['a']->usable);
        $this->assertSame('0.0000', (string) $balances['b']->held);
        $this->assertSame('3.0000', (string) $balances['b']->usable);

        // The overview's location-agnostic "available" is on-hand 9 minus QA-held 2; with no unusable
        // location in play, this location-aware figure must equal it.
        $usable = $balances->reduce(fn ($sum, StockBalance $b) => $sum->plus($b->usable), BigDecimal::zero());
        $overview = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/inventory-overview?filter[search]='.$item->code)
            ->assertOk()->json('data.0.available_qty');
        $this->assertSame($overview, (string) $usable->toScale(4));
    }

    public function test_it_reads_without_writing(): void
    {
        $item = $this->item('paint');
        $this->receive($item, $this->warehouse('a'), '3', '10.0000');
        $counts = fn (): array => [
            DB::table('stock_moves')->count(), DB::table('stock_layers')->count(), DB::table('avco_pools')->count(),
            DB::table('stock_valuation_entries')->count(), DB::table('negative_stock_shortages')->count(),
        ];
        $before = $counts();

        $this->snapshot([$item->id]);

        $this->assertSame($before, $counts());
    }

    private function item(string $code, AvcoPoolScope $scope = AvcoPoolScope::CompanyItem, ValuationMethod $valuation = ValuationMethod::Avco, string $tracking = 'NONE'): Item
    {
        return Item::create([
            'code' => $code, 'name' => ucfirst($code), 'type' => 'RAW', 'stock_uom_id' => $this->uom->id,
            'category_id' => $this->category->id, 'is_stocked' => true, 'valuation_method' => $valuation,
            'avco_pool_scope' => $valuation === ValuationMethod::Avco ? $scope : null,
            'tracking' => $tracking, 'inventory_account_id' => $this->inventoryAccount->id,
        ]);
    }

    /** A new warehouse; returns its single, automatically created 'main' location. */
    private function warehouse(string $code): Location
    {
        return app(CreateWarehouse::class)(['code' => $code, 'name' => $code, 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();
    }

    private function receive(Item $item, Location $location, string $qty, string $cost, ?string $batch = null): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $this->uom->id,
            'unit_cost' => $cost, 'base_unit_cost' => $cost, 'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1',
            'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->sourceId, 'moved_at' => Carbon::now(), 'batch_no' => $batch,
        ]);
    }

    /** @return array<string, mixed> */
    private function issueInput(Item $item, Location $location, string $qty): array
    {
        return [
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $this->uom->id,
            'source_type' => 'TEST_ISSUE', 'source_id' => ++$this->sourceId, 'moved_at' => Carbon::now(),
        ];
    }

    /** @param list<int> $itemIds */
    private function snapshot(array $itemIds, ?InventoryScope $scope = null): StockAvailabilitySnapshot
    {
        return app(QueryStockAvailability::class)($itemIds, $scope ?? new InventoryScope);
    }

    /**
     * @param  list<StockBalance>  $balances
     * @return array<string, string>
     */
    private function usableByLocation(array $balances): array
    {
        $out = [];
        foreach ($balances as $balance) {
            $out[$balance->warehouseCode.'/'.$balance->locationCode] = (string) $balance->usable;
        }

        return $out;
    }

    /**
     * @param  list<StockBalance>  $balances
     * @return array<string, string>
     */
    private function costByLocation(array $balances): array
    {
        $out = [];
        foreach ($balances as $balance) {
            $out[$balance->warehouseCode.'/'.$balance->locationCode] = (string) $balance->unitCost;
        }

        return $out;
    }

    private function receiveThroughQaRequiredPurchaseOrder(Item $item, Location $location, string $received, string $passed): void
    {
        $currency = Currency::firstOrFail();
        $party = Party::create(['code' => 'SUP', 'name' => 'SUP']);
        $payable = ChartOfAccount::create(['code' => 'pay-sup', 'name' => 'Payable', 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => 'SUP', 'payment_terms_days' => 30, 'payable_account_id' => $payable->id]);
        $site = SupplierSite::create([
            'supplier_account_id' => $supplier->id, 'role' => 'ORDER_FROM',
            'party_site_id' => PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main'])->id,
        ]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();
        $line = $order->lines()->create([
            'purchase_order_id' => $order->id, 'line_number' => 1, 'item_id' => $item->id,
            'description' => 'Item', 'ordered_qty' => '10.0000', 'purchase_uom_id' => $this->uom->id,
            'ordered_stock_qty' => '10.0000', 'stock_uom_id' => $this->uom->id, 'uom_factor' => '1.000000000',
            'unit_price' => '10.0000', 'line_amount' => '100.0000',
            'base_unit_price' => '10.0000', 'base_line_amount' => '100.0000', 'qa_required' => true,
        ]);
        $user = PurchasingActor::make([
            'purchasing.purchase-order.issue',
            'purchasing.goods-receipt.create',
            'purchasing.commercial-cost.view',
            'purchasing.goods-receipt.post',
            'purchasing.quality-disposition.post',
        ]);
        app(IssuePurchaseOrder::class)($order, $user);

        $receiptId = $this->actingAs($user)->postJson('/api/v1/goods-receipts', [
            'supplier_account_id' => $supplier->id,
            'physical_arrival_at' => now()->toIso8601String(), 'posting_date' => now()->format('Y-m-d'),
            'lines' => [[
                'purchase_order_line_id' => $line->id, 'item_id' => $item->id, 'location_id' => $location->id,
                'received_qty' => $received, 'received_uom_id' => $this->uom->id, 'provisional_unit_cost' => '10.0000',
                'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
                'fx_rate_date' => now()->format('Y-m-d'), 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            ]],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $receiptLineId = DB::table('goods_receipt_lines')->where('item_id', $item->id)->value('id');
        $this->postJson("/api/v1/goods-receipt-lines/{$receiptLineId}/quality-dispositions", [
            'disposition' => 'PASS', 'qty' => $passed, 'reason' => 'Inspected and passed',
        ])->assertCreated();
    }
}

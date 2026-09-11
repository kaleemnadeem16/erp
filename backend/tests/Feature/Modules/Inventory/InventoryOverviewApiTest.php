<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\NegativeStockOverrideRequest;
use App\Modules\Inventory\Models\NegativeStockShortage;
use App\Modules\Inventory\Models\StockMove;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\Party;
use App\Modules\Parties\Models\PartySite;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use App\Modules\Purchasing\Actions\IssuePurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifies each of the five aggregated figures independently against
 * App\Modules\Inventory\Actions\BuildInventoryOverviewQuery's actual SQL —
 * the risk here is the join/subquery math, not the underlying PO/receipt/QA
 * domain logic, which the rest of the Purchasing suite already covers.
 */
class InventoryOverviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/inventory-overview')->assertUnauthorized();
    }

    public function test_index_computes_on_hand_available_qa_held_on_order_and_shortage(): void
    {
        $item = $this->seedFullScenario();

        $response = $this->getJson('/api/v1/inventory-overview?filter[search]='.$item->code);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        // 6 received, AVCO on-hand.
        $response->assertJsonPath('data.0.on_hand_qty', '6.0000');
        // Ordered 10, received 6, short-closed 0 -> 4 still outstanding.
        $response->assertJsonPath('data.0.on_order_qty', '4.0000');
        // Received 6, only 4 have a PASS disposition -> 2 still QA-held.
        $response->assertJsonPath('data.0.qa_held_qty', '2.0000');
        // On-hand 6 minus QA-held 2.
        $response->assertJsonPath('data.0.available_qty', '4.0000');
        $response->assertJsonPath('data.0.shortage_qty', '3.0000');
    }

    /**
     * Regression test: on_hand_value/available_value were exposed per-row for
     * the first time alongside type/stock_uom_id, and available_value's
     * rounding requirement (see the summary-endpoint test above for why) was
     * initially missed here — the per-row resource used the same
     * default-toScale(4) call as on_hand_value, which throws instead of
     * rounding once the SQL division doesn't terminate cleanly.
     */
    public function test_index_includes_type_uom_and_rounds_available_value_when_the_ratio_does_not_terminate(): void
    {
        $item = $this->seedFullScenario();
        DB::table('avco_pools')->where('item_id', $item->id)->update(['inventory_value' => '61.0000']);

        $response = $this->getJson('/api/v1/inventory-overview?filter[search]='.$item->code);

        $response->assertOk();
        $response->assertJsonPath('data.0.type', $item->type->value);
        $response->assertJsonPath('data.0.stock_uom_id', $item->stock_uom_id);
        $response->assertJsonPath('data.0.on_hand_value', '61.0000');
        // 61 * (4/6) = 40.6666... -> rounds to 40.6667, same as the summary endpoint.
        $response->assertJsonPath('data.0.available_value', '40.6667');
    }

    public function test_summary_computes_value_and_counts_across_the_filtered_set(): void
    {
        $item = $this->seedFullScenario();

        $response = $this->getJson('/api/v1/inventory-overview/summary?filter[search]='.$item->code);

        $response->assertOk();
        // AVCO pool: 6 units received @ 10.0000 provisional cost, this item's only receipt.
        $response->assertJsonPath('data.on_hand_value', '60.0000');
        // 60 on-hand value prorated by the 4/6 available/on-hand qty ratio.
        $response->assertJsonPath('data.available_value', '40.0000');
        $response->assertJsonPath('data.item_count', 1);
        $response->assertJsonPath('data.shortage_item_count', 1);
        $response->assertJsonPath('data.qa_held_item_count', 1);
    }

    public function test_summary_rounds_available_value_when_the_ratio_does_not_terminate(): void
    {
        $item = $this->seedFullScenario();

        // Perturb the pool's value away from a clean multiple of qty — this is exactly the shape
        // that broke InventoryOverviewSummaryResource against real demo data: on_hand_value *
        // available_qty / on_hand_qty came back with more than 4 decimal digits, and the default
        // `toScale(4)` (RoundingMode::UNNECESSARY) threw instead of rounding. 61 isn't a multiple of 6.
        DB::table('avco_pools')->where('item_id', $item->id)->update(['inventory_value' => '61.0000']);

        $response = $this->getJson('/api/v1/inventory-overview/summary?filter[search]='.$item->code);

        $response->assertOk();
        // 61 * (4/6) = 40.6666... -> rounds to 40.6667.
        $response->assertJsonPath('data.available_value', '40.6667');
    }

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/inventory-overview/summary')->assertUnauthorized();
    }

    public function test_summary_defaults_to_zero_for_an_item_with_no_activity(): void
    {
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        Item::create([
            'code' => 'idle', 'name' => 'Idle Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id,
            'category_id' => $category->id, 'is_stocked' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/inventory-overview/summary?filter[search]=idle');

        $response->assertOk();
        $response->assertJsonPath('data.on_hand_value', '0.0000');
        $response->assertJsonPath('data.available_value', '0.0000');
        $response->assertJsonPath('data.item_count', 1);
        $response->assertJsonPath('data.shortage_item_count', 0);
        $response->assertJsonPath('data.qa_held_item_count', 0);
    }

    /**
     * The shared fixture behind both the index() and summary() tests: one AVCO,
     * batch-less item with a 6-unit receipt (4 QA-passed, 2 still held), an
     * outstanding 4-unit balance on its issued PO, and a real 3-unit open
     * shortage referencing a dedicated outbound move (the schema's trigger
     * requires the move to actually be outbound from the shortage's location —
     * see the inline comment where it's built).
     */
    private function seedFullScenario(): Item
    {
        [$order, $item, $uom] = $this->makeOrder();
        $item->update([
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco,
            'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => ChartOfAccount::query()->firstOrFail()->id,
        ]);
        $line = $order->lines()->create(array_merge($this->lineAttributes($order, $item, $uom), [
            'ordered_qty' => '10.0000', 'ordered_stock_qty' => '10.0000', 'line_amount' => '100.0000',
            'qa_required' => true,
        ]));
        app(IssuePurchaseOrder::class)($order);

        $locationId = $this->locationId();
        $receiptId = $this->createReceipt($line->id, $item->id, $uom->id, $locationId, ['received_qty' => '6.0000']);
        $this->postJson("/api/v1/goods-receipts/{$receiptId}/post")->assertOk();

        $receiptLineId = DB::table('goods_receipt_lines')->where('item_id', $item->id)->value('id');
        $this->actingAs(User::factory()->create())->postJson("/api/v1/goods-receipt-lines/{$receiptLineId}/quality-dispositions", [
            'disposition' => 'PASS', 'qty' => '4.0000', 'reason' => 'Inspected and passed',
        ])->assertCreated();

        // A synthetic open shortage against the same item. The trigger enforcing shortage/move
        // consistency requires an *outbound* move from the shortage's own location (from_location_id
        // set, to_location_id null) — the receipt's move is inbound, so a dedicated move is created
        // here rather than reusing it, exercising the schema's real FKs without re-driving the full
        // negative-stock-override workflow (already covered by NegativeStockOverrideWorkflowTest).
        $outboundMove = StockMove::query()->create([
            'item_id' => $item->id, 'from_location_id' => $locationId, 'to_location_id' => null,
            'qty' => '3.0000', 'uom_id' => $uom->id, 'moved_at' => now(),
            'source_type' => 'Test', 'source_id' => 999,
            'unit_cost' => '10.0000', 'currency_id' => Currency::query()->firstOrFail()->id, 'fx_rate' => '1.000000000',
        ]);
        $overrideRequest = NegativeStockOverrideRequest::query()->create([
            'item_id' => $item->id, 'location_id' => $locationId, 'movement_class' => 'SALES_DESPATCH',
            'requested_qty' => '3.0000', 'available_qty' => '0.0000', 'shortage_qty' => '3.0000',
            'source_type' => 'Test', 'source_id' => 999, 'reason_code' => 'OPERATIONAL_EMERGENCY',
            'explanation' => 'Test fixture for inventory overview shortage figure',
            'authorisation_mode' => 'IMMEDIATE_WITH_REVIEW', 'allow_self_review' => false,
            'requested_by' => User::factory()->create()->id, 'requested_at' => now(),
        ]);
        NegativeStockShortage::query()->create([
            'item_id' => $item->id, 'location_id' => $locationId,
            'qty_open' => '3.0000', 'provisional_unit_cost' => '10.0000', 'status' => 'OPEN',
            'source_move_id' => $outboundMove->id, 'override_request_id' => $overrideRequest->id,
        ]);

        return $item;
    }

    public function test_index_defaults_to_zero_figures_for_an_item_with_no_activity(): void
    {
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        Item::create([
            'code' => 'idle', 'name' => 'Idle Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id,
            'category_id' => $category->id, 'is_stocked' => true,
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/inventory-overview?filter[search]=idle');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.on_hand_qty', '0.0000');
        $response->assertJsonPath('data.0.available_qty', '0.0000');
        $response->assertJsonPath('data.0.qa_held_qty', '0.0000');
        $response->assertJsonPath('data.0.on_order_qty', '0.0000');
        $response->assertJsonPath('data.0.shortage_qty', '0.0000');
    }

    public function test_a_non_stocked_item_is_excluded(): void
    {
        $category = Category::create(['code' => 'svc', 'name' => 'Service', 'default_valuation_method' => 'AVCO']);
        $uom = Uom::create(['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        Item::create([
            'code' => 'labour', 'name' => 'Labour Hour', 'type' => 'LABOUR', 'stock_uom_id' => $uom->id,
            'category_id' => $category->id, 'is_stocked' => false,
        ]);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/inventory-overview?filter[search]=labour')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_rejects_invalid_sort(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/inventory-overview?sort=on_hand_qty')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_another_companys_item_is_not_visible(): void
    {
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        Item::create([
            'code' => 'shared-code', 'name' => 'Company A Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id,
            'category_id' => $category->id, 'is_stocked' => true,
        ]);

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs(User::factory()->create())->getJson('/api/v1/inventory-overview')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array{PurchaseOrder, Item, Uom} */
    private function makeOrder(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        [$supplier, $site] = $this->makeSupplier('SUP');
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create(['code' => 'overview-item', 'name' => 'Overview Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id]);
        $order = PurchaseOrder::create([
            'supplier_account_id' => $supplier->id, 'supplier_site_id' => $site->id,
            'currency_id' => $currency->id, 'fx_rate' => '1.000000000',
            'fx_rate_date' => '2026-09-02', 'fx_rate_type' => 'SPOT', 'fx_rate_source' => 'manual',
            'order_date' => '2026-09-02',
        ])->refresh();

        return [$order, $item, $uom];
    }

    /** @return array{SupplierAccount, SupplierSite} */
    private function makeSupplier(string $code): array
    {
        $party = Party::create(['code' => $code, 'name' => $code]);
        $account = ChartOfAccount::create(['code' => 'pay-'.$code, 'name' => 'Payable '.$code, 'type' => AccountType::Liability]);
        $supplier = SupplierAccount::create(['party_id' => $party->id, 'code' => $code, 'payment_terms_days' => 30, 'payable_account_id' => $account->id]);
        $partySite = PartySite::create(['party_id' => $party->id, 'code' => 'main', 'name' => 'Main']);
        $site = SupplierSite::create(['supplier_account_id' => $supplier->id, 'party_site_id' => $partySite->id, 'role' => 'ORDER_FROM']);

        return [$supplier, $site];
    }

    /** @return array<string, mixed> */
    private function lineAttributes(PurchaseOrder $order, Item $item, Uom $uom, int $lineNumber = 1): array
    {
        return [
            'purchase_order_id' => $order->id, 'line_number' => $lineNumber, 'item_id' => $item->id,
            'description' => 'Item', 'ordered_qty' => '2.0000', 'purchase_uom_id' => $uom->id,
            'ordered_stock_qty' => '2.0000', 'stock_uom_id' => $uom->id, 'uom_factor' => '1.000000000',
            'unit_price' => '10.0000', 'line_amount' => '20.0000',
            'base_unit_price' => '10.0000', 'base_line_amount' => '20.0000',
        ];
    }

    private function locationId(): int
    {
        $warehouse = app(CreateWarehouse::class)([
            'code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main',
        ]);

        return $warehouse->locations()->sole()->id;
    }

    /** @param array<string, mixed> $overrides */
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

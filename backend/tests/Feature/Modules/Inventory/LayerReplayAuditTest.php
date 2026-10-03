<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\DecideNegativeStockOverride;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\Recalculation\LayerCounterfactualReplayer;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class LayerReplayAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.negative-stock.request', 'inventory.negative-stock.approve'] as $permission) {
            Permission::findOrCreate($permission);
        }
    }

    private function replayer(): LayerCounterfactualReplayer
    {
        return app(LayerCounterfactualReplayer::class);
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeItem(ValuationMethod $method, ItemTracking $tracking = ItemTracking::None): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => $method->value]);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => $method, 'tracking' => $tracking,
            'avco_pool_scope' => $method === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();

        return [$item, $uom, $location];
    }

    private function postReceiptAt(Item $item, Uom $uom, Location $location, string $qty, string $cost, Carbon $movedAt, int $sourceId, ?string $serialNo = null): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $uom->id,
            'moved_at' => $movedAt, 'source_type' => 'TEST_RECEIPT', 'source_id' => $sourceId,
            'unit_cost' => $cost, 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => $cost, 'serial_no' => $serialNo,
        ]);
    }

    private function postIssueAt(Item $item, Uom $uom, Location $location, string $qty, Carbon $movedAt, int $sourceId, ?string $serialNo = null): void
    {
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $uom->id,
            'moved_at' => $movedAt, 'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId, 'serial_no' => $serialNo,
        ]);
    }

    public function test_reversal_does_not_resurrect_stock_and_stays_tied_to_its_layer(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '5.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        app(PostInventoryMovement::class)->reverseReceipt('TEST_RECEIPT', 1, 'TEST_REVERSAL', 2, Carbon::parse('2026-01-10'));
        foreach (['2026-01-05', '2026-01-11'] as $date) {
            $preview = $this->replayer()->replay($item, $location, null, null, BigDecimal::of('2'), BigDecimal::of('20'), Carbon::parse($date));
            $this->assertSame('2.0000', (string) $preview->counterfactualQtyOnHand);
            $this->assertSame('40.0000', (string) $preview->counterfactualInventoryValue);
        }
    }

    public function test_transferred_stock_cannot_fund_an_issue_before_arrival_but_keeps_fifo_age_afterwards(): void
    {
        [$item, $uom, $source] = $this->makeItem(ValuationMethod::Fifo);
        $destination = app(CreateWarehouse::class)(['code' => 'other', 'name' => 'Other', 'location_code' => 'other', 'location_name' => 'Other'], PurchasingActor::make(['inventory.warehouse.manage']))->locations()->sole();
        $this->postReceiptAt($item, $uom, $source, '5', '10', Carbon::parse('2026-01-01'), 1);
        $this->postReceiptAt($item, $uom, $destination, '5', '30', Carbon::parse('2026-01-02'), 2);
        $this->postIssueAt($item, $uom, $destination, '3', Carbon::parse('2026-01-05'), 3);
        app(PostInventoryMovement::class)->transfer([
            'item_id' => $item->id, 'from_location_id' => $source->id, 'to_location_id' => $destination->id,
            'qty' => '5', 'uom_id' => $uom->id, 'moved_at' => Carbon::parse('2026-01-10'),
            'source_type' => 'TEST_TRANSFER', 'source_id' => 4,
        ]);
        $this->postIssueAt($item, $uom, $destination, '4', Carbon::parse('2026-01-11'), 5);
        $preview = $this->replayer()->replay($item, $destination, null, null, BigDecimal::of('2'), BigDecimal::of('20'), Carbon::parse('2026-01-03'));
        // Jan 5 consumes the local 30-cost layer; Jan 11 consumes transferred 10-cost stock.
        $this->assertSame('5.0000', (string) $preview->counterfactualQtyOnHand);
        $this->assertSame('110.0000', (string) $preview->counterfactualInventoryValue);
    }

    public function test_incomplete_legacy_consumption_history_is_refused(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '5', '10', Carbon::parse('2026-01-01'), 1);
        // A legitimate pre-ledger balance: no historical allocation row exists to reconstruct.
        StockLayer::query()->sole()->update(['qty_remaining' => '2']);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('history is incomplete');
        $this->replayer()->replay($item, $location, null, null, BigDecimal::of('2'), BigDecimal::of('20'), Carbon::parse('2026-01-03'));
    }

    public function test_a_shortage_created_before_the_proposed_date_is_refused_when_settled_later(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '2', '10', Carbon::parse('2026-01-01'), 1);
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');
        $request = app(RequestNegativeStockOverride::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => '5', 'available_qty' => '2', 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Delayed paperwork',
        ], $requester);
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.negative-stock.approve');
        app(DecideNegativeStockOverride::class)($request, NegativeStockApprovalDecision::Approved, 'checked', $approver);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-02'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2, 'override_request_id' => $request->id,
        ]);
        $this->postReceiptAt($item, $uom, $location, '10', '20', Carbon::parse('2026-01-10'), 3);
        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('shortage activity');
        $this->replayer()->replay($item, $location, null, null, BigDecimal::of('2'), BigDecimal::of('20'), Carbon::parse('2026-01-05'));
    }

    public function test_raw_consumption_mutation_is_rejected_by_the_database(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '5', '10', Carbon::parse('2026-01-01'), 1);
        $this->postIssueAt($item, $uom, $location, '2', Carbon::parse('2026-01-02'), 2);
        $this->expectException(QueryException::class);
        DB::table('stock_layer_consumptions')->update(['qty_consumed' => '1']);
    }
}

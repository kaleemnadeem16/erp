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
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

class LayerCounterfactualReplayerTest extends TestCase
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

    public function test_a_backdated_receipt_that_the_only_existing_layer_already_covers_leaves_the_later_issue_untouched(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '10.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postIssueAt($item, $uom, $location, '6.0000', Carbon::parse('2026-01-10'), 2);

        $preview = $this->replayer()->replay($item, $location, null, null, BigDecimal::of('5.0000'), BigDecimal::of('20.0000'), Carbon::parse('2026-01-05'));

        $this->assertSame('4.0000', (string) $preview->originalQtyOnHand);
        $this->assertSame('40.0000', (string) $preview->originalInventoryValue);
        $this->assertSame('9.0000', (string) $preview->counterfactualQtyOnHand);
        $this->assertSame('140.0000', (string) $preview->counterfactualInventoryValue);
        $this->assertSame('100.0000', (string) $preview->valuationAdjustment());
    }

    public function test_a_backdated_receipt_bumps_a_later_receipts_layer_out_of_the_issue(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '4.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postReceiptAt($item, $uom, $location, '10.0000', '15.0000', Carbon::parse('2026-01-03'), 2);
        $this->postIssueAt($item, $uom, $location, '6.0000', Carbon::parse('2026-01-10'), 3);

        $preview = $this->replayer()->replay($item, $location, null, null, BigDecimal::of('5.0000'), BigDecimal::of('20.0000'), Carbon::parse('2026-01-02'));

        // Actual: issue took all 4 from the Jan-1 layer plus 2 from the Jan-3
        // layer, leaving 0 + 8 = 8 units worth 8*15 = 120.
        $this->assertSame('8.0000', (string) $preview->originalQtyOnHand);
        $this->assertSame('120.0000', (string) $preview->originalInventoryValue);
        // Counterfactual: the inserted layer sits between the two real ones,
        // so the issue now takes 4 from Jan-1 + 2 from the inserted layer —
        // the Jan-3 layer is never touched and stays fully intact at 10.
        $this->assertSame('13.0000', (string) $preview->counterfactualQtyOnHand);
        $this->assertSame('210.0000', (string) $preview->counterfactualInventoryValue);
        $this->assertSame('90.0000', (string) $preview->valuationAdjustment());
        // Conservation: physical quantity is unaffected by cost — the
        // counterfactual total is always exactly original + proposed.
        $this->assertTrue($preview->counterfactualQtyOnHand->isEqualTo(
            $preview->originalQtyOnHand->plus('5.0000'),
        ));
    }

    public function test_replaying_with_no_proposed_change_at_the_present_reproduces_the_live_layers_exactly(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '4.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postReceiptAt($item, $uom, $location, '10.0000', '15.0000', Carbon::parse('2026-01-03'), 2);
        $this->postIssueAt($item, $uom, $location, '6.0000', Carbon::parse('2026-01-10'), 3);

        $preview = $this->replayer()->replay($item, $location, null, null, BigDecimal::zero(), BigDecimal::zero(), Carbon::parse('2026-01-11'));

        $this->assertTrue($preview->counterfactualQtyOnHand->isEqualTo($preview->originalQtyOnHand));
        $this->assertTrue($preview->counterfactualInventoryValue->isEqualTo($preview->originalInventoryValue));
        $this->assertSame('0.0000', (string) $preview->valuationAdjustment());
    }

    public function test_a_specific_items_new_serial_never_affects_any_other_movements_cost(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Specific, ItemTracking::Serial);
        $this->postReceiptAt($item, $uom, $location, '1.0000', '25.0000', Carbon::parse('2026-01-01'), 1, serialNo: 'SER-OTHER');
        $this->postIssueAt($item, $uom, $location, '1.0000', Carbon::parse('2026-01-10'), 2, serialNo: 'SER-OTHER');

        $preview = $this->replayer()->replay($item, $location, null, 'SER-NEW', BigDecimal::of('1.0000'), BigDecimal::of('30.0000'), Carbon::parse('2026-01-05'));

        $this->assertSame('0.0000', (string) $preview->originalQtyOnHand);
        $this->assertSame('1.0000', (string) $preview->counterfactualQtyOnHand);
        $this->assertSame('30.0000', (string) $preview->counterfactualInventoryValue);
        $this->assertSame('30.0000', (string) $preview->valuationAdjustment());
    }

    public function test_refuses_when_a_shortage_was_created_after_the_proposed_date(): void
    {
        [$item, $uom, $location] = $this->makeItem(ValuationMethod::Fifo);
        $this->postReceiptAt($item, $uom, $location, '2.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');
        $request = app(RequestNegativeStockOverride::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'movement_class' => 'MATERIAL_CONSUMPTION',
            'requested_qty' => '5.0000', 'available_qty' => '2.0000',
            'source_type' => 'TEST_ISSUE', 'source_id' => 2,
            'reason_code' => 'PAPERWORK_DELAY', 'explanation' => 'Receipt paperwork is delayed',
        ], $requester);
        $approver = User::factory()->create();
        $approver->givePermissionTo('inventory.negative-stock.approve');
        app(DecideNegativeStockOverride::class)($request, NegativeStockApprovalDecision::Approved, 'checked', $approver);
        app(PostInventoryMovement::class)->issue(array_merge([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ], ['override_request_id' => $request->id]));

        $this->expectException(DomainInvariantException::class);
        $this->replayer()->replay($item, $location, null, null, BigDecimal::of('10.0000'), BigDecimal::of('10.0000'), Carbon::parse('2026-01-05'));
    }
}

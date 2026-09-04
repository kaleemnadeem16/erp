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
use App\Modules\Inventory\Actions\Recalculation\AvcoCounterfactualReplayer;
use App\Modules\Inventory\Actions\RequestNegativeStockOverride;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\NegativeStockApprovalDecision;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AvcoCounterfactualReplayerTest extends TestCase
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

    private function replayer(): AvcoCounterfactualReplayer
    {
        return app(AvcoCounterfactualReplayer::class);
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeAvcoItem(): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();

        return [$item, $uom, $location];
    }

    private function postReceiptAt(Item $item, Uom $uom, Location $location, string $qty, string $cost, Carbon $movedAt, int $sourceId): void
    {
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $uom->id,
            'moved_at' => $movedAt, 'source_type' => 'TEST_RECEIPT', 'source_id' => $sourceId,
            'unit_cost' => $cost, 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => $cost,
        ]);
    }

    private function postIssueAt(Item $item, Uom $uom, Location $location, string $qty, Carbon $movedAt, int $sourceId): void
    {
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => $qty, 'uom_id' => $uom->id,
            'moved_at' => $movedAt, 'source_type' => 'TEST_ISSUE', 'source_id' => $sourceId,
        ]);
    }

    public function test_a_backdated_receipt_reprices_a_single_later_issue_at_the_counterfactual_average(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->postReceiptAt($item, $uom, $location, '10.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postIssueAt($item, $uom, $location, '5.0000', Carbon::parse('2026-01-10'), 2);

        $preview = $this->replayer()->replay($item, $location, null, BigDecimal::of('10.0000'), BigDecimal::of('20.0000'), Carbon::parse('2026-01-05'));

        $this->assertSame('5.0000', (string) $preview->originalQtyOnHand);
        $this->assertSame('50.0000', (string) $preview->originalInventoryValue);
        $this->assertSame('15.0000', (string) $preview->counterfactualQtyOnHand);
        $this->assertSame('225.0000', (string) $preview->counterfactualInventoryValue);
        $this->assertSame('15.0000', (string) $preview->counterfactualAvgUnitCost);
        $this->assertSame('175.0000', (string) $preview->valuationAdjustment());
    }

    public function test_a_later_receipt_passes_through_unchanged_in_the_fold(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->postReceiptAt($item, $uom, $location, '10.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postIssueAt($item, $uom, $location, '5.0000', Carbon::parse('2026-01-10'), 2);
        $this->postReceiptAt($item, $uom, $location, '5.0000', '30.0000', Carbon::parse('2026-01-15'), 3);

        $preview = $this->replayer()->replay($item, $location, null, BigDecimal::of('10.0000'), BigDecimal::of('20.0000'), Carbon::parse('2026-01-05'));

        $this->assertSame('10.0000', (string) $preview->originalQtyOnHand);
        $this->assertSame('200.0000', (string) $preview->originalInventoryValue);
        $this->assertSame('20.0000', (string) $preview->counterfactualQtyOnHand);
        $this->assertSame('375.0000', (string) $preview->counterfactualInventoryValue);
        $this->assertSame('18.7500', (string) $preview->counterfactualAvgUnitCost);
        $this->assertSame('175.0000', (string) $preview->valuationAdjustment());
    }

    public function test_replaying_with_no_proposed_change_at_the_present_reproduces_the_live_pool_exactly(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->postReceiptAt($item, $uom, $location, '10.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $this->postIssueAt($item, $uom, $location, '5.0000', Carbon::parse('2026-01-10'), 2);
        $this->postReceiptAt($item, $uom, $location, '5.0000', '30.0000', Carbon::parse('2026-01-15'), 3);
        $live = AvcoPool::query()->sole();

        $preview = $this->replayer()->replay($item, $location, null, BigDecimal::zero(), BigDecimal::zero(), Carbon::parse('2026-01-16'));

        $this->assertSame($live->qty_on_hand, (string) $preview->counterfactualQtyOnHand);
        $this->assertSame($live->inventory_value, (string) $preview->counterfactualInventoryValue);
        $this->assertSame('0.0000', (string) $preview->valuationAdjustment());
    }

    public function test_refuses_when_the_pool_was_already_non_positive_at_the_proposed_date(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->postReceiptAt($item, $uom, $location, '2.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);
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
        $this->replayer()->replay($item, $location, null, BigDecimal::of('1.0000'), BigDecimal::of('10.0000'), Carbon::parse('2026-01-15'));
    }

    public function test_refuses_when_a_shortage_was_created_after_the_proposed_date(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        $this->postReceiptAt($item, $uom, $location, '2.0000', '10.0000', Carbon::parse('2026-01-01'), 1);
        $requester = User::factory()->create();
        $requester->givePermissionTo('inventory.negative-stock.request');
        Company::query()->findOrFail(1)->update(['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'negative_stock_authorisation_mode' => 'PRIOR_APPROVAL']);
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
        // T (Jan 5) is before the shortage-creating issue (Jan 10) and the
        // pool was still positive at T — this exercises the window guard,
        // not the at-T guard covered by the test above.
        $this->replayer()->replay($item, $location, null, BigDecimal::of('10.0000'), BigDecimal::of('10.0000'), Carbon::parse('2026-01-05'));
    }
}

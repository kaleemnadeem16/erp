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
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\RequestStockRecalculation;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\RecalculationImpactVersion;
use App\Modules\Inventory\Models\RecalculationRequest;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RequestStockRecalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.recalculation.request');
        // A fixed, distant posting floor keeps these tests independent of
        // accounting-period fixtures — CURRENT_AND_PREVIOUS_PERIOD is covered
        // by BackdatingHorizonEvaluatorTest, not re-tested here.
        Company::query()->findOrFail(1)->update([
            'authorised_forward_recalculation_enabled' => true,
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2020-01-01',
        ]);
    }

    /** @return array{0: Item, 1: Uom, 2: Location} */
    private function makeAvcoItem(?ValuationMethod $method = null): array
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $item = Item::create([
            'code' => 'item', 'name' => 'Item', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => $method ?? ValuationMethod::Avco,
            'avco_pool_scope' => ($method ?? ValuationMethod::Avco) === ValuationMethod::Avco ? AvcoPoolScope::CompanyItem : null,
            'inventory_account_id' => ChartOfAccount::query()->firstOr(fn () => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]))->id,
        ]);
        $location = app(CreateWarehouse::class)(['code' => 'main', 'name' => 'Main', 'location_code' => 'main', 'location_name' => 'Main'])->locations()->sole();

        return [$item, $uom, $location];
    }

    private function requestInput(Item $item, Location $location, Carbon $movedAt, int $sourceId): array
    {
        return [
            'item_id' => $item->id, 'location_id' => $location->id,
            'qty' => '10.0000', 'unit_cost' => '20.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '20.0000', 'moved_at' => $movedAt,
            'source_type' => 'TEST_BACKDATED_RECEIPT', 'source_id' => $sourceId,
        ];
    }

    public function test_disabled_policy_blocks_the_request(): void
    {
        Company::query()->findOrFail(1)->update(['authorised_forward_recalculation_enabled' => false]);
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.request');

        $this->expectException(DomainInvariantException::class);
        app(RequestStockRecalculation::class)($this->requestInput($item, $location, Carbon::parse('2026-01-05'), 1), $actor);
    }

    public function test_actor_without_permission_is_rejected(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        $actor = User::factory()->create();

        $this->expectException(DomainInvariantException::class);
        app(RequestStockRecalculation::class)($this->requestInput($item, $location, Carbon::parse('2026-01-05'), 1), $actor);
    }

    public function test_fifo_items_are_not_yet_supported(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem(ValuationMethod::Fifo);
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.request');

        $this->expectException(DomainInvariantException::class);
        app(RequestStockRecalculation::class)($this->requestInput($item, $location, Carbon::parse('2026-01-05'), 1), $actor);
    }

    public function test_a_date_that_does_not_conflict_with_later_history_is_rejected(): void
    {
        [$item, , $location] = $this->makeAvcoItem();
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.request');

        $this->expectException(DomainInvariantException::class);
        app(RequestStockRecalculation::class)($this->requestInput($item, $location, Carbon::parse('2026-01-05'), 1), $actor);
    }

    public function test_a_date_outside_the_configured_horizon_is_rejected(): void
    {
        Company::query()->findOrFail(1)->update(['backdating_posting_floor_fixed_date' => '2026-01-08']);
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.request');

        $this->expectException(DomainInvariantException::class);
        app(RequestStockRecalculation::class)($this->requestInput($item, $location, Carbon::parse('2026-01-05'), 1), $actor);
    }

    public function test_a_valid_conflicting_receipt_creates_a_request_with_its_first_impact_version(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.request');

        $request = app(RequestStockRecalculation::class)($this->requestInput($item, $location, Carbon::parse('2026-01-05'), 3), $actor);

        $this->assertInstanceOf(RecalculationRequest::class, $request);
        $this->assertSame($actor->id, $request->requested_by);
        $this->assertSame('POSTING_FLOOR', $request->horizon_mode->value);
        $this->assertNotNull($request->review_due_at);
        $version = $request->impactVersions->sole();
        $this->assertSame(1, $version->version_no);
        $this->assertFalse($version->is_stale);
        // Original = the live pool at computation time: qty 5 @ 10 received,
        // then 2 issued at that same rate → 3 @ 30.0000 remaining.
        $this->assertSame('30.0000', $version->original_inventory_value);
    }

    public function test_a_retry_with_the_same_source_is_idempotent(): void
    {
        [$item, $uom, $location] = $this->makeAvcoItem();
        app(PostInventoryMovement::class)([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '5.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-01'), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '10.0000', 'currency_id' => Company::query()->findOrFail(1)->base_currency_id,
            'fx_rate' => '1.000000000', 'base_unit_cost' => '10.0000',
        ]);
        app(PostInventoryMovement::class)->issue([
            'item_id' => $item->id, 'location_id' => $location->id, 'qty' => '2.0000', 'uom_id' => $uom->id,
            'moved_at' => Carbon::parse('2026-01-10'), 'source_type' => 'TEST_ISSUE', 'source_id' => 2,
        ]);
        $actor = User::factory()->create();
        $actor->givePermissionTo('inventory.recalculation.request');
        $input = $this->requestInput($item, $location, Carbon::parse('2026-01-05'), 3);

        $first = app(RequestStockRecalculation::class)($input, $actor);
        $second = app(RequestStockRecalculation::class)($input, $actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, RecalculationImpactVersion::query()->count());
    }
}

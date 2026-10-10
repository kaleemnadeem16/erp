<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Estimating\Actions\ReadMaterialCost;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Actions\QueryMaterialCost;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Modules\Inventory\Support\Costing\CostResolution;
use App\Modules\Inventory\Support\Costing\CostStatus;
use App\Modules\Inventory\Support\Costing\MaterialCostRequest;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QueryMaterialCostTest extends TestCase
{
    use RefreshDatabase;

    private Uom $uom;

    private Category $category;

    private ChartOfAccount $account;

    private Location $location;

    private int $sourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $this->uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => 'COUNT', 'factor_to_base' => '1']);
        $this->category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->account = ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => 'ASSET']);
        $actor = PermissionedActor::grant(User::factory()->create(), ['inventory.warehouse.manage']);
        $this->location = app(CreateWarehouse::class)(['code' => 'a', 'name' => 'A', 'location_code' => 'main', 'location_name' => 'Main'], $actor)->locations()->sole();
    }

    public function test_absence_is_unknown_but_a_valid_zero_pool_is_costed_even_with_physical_shortage(): void
    {
        $item = $this->item('free');
        $this->assertSame(CostStatus::Unknown, $this->cost($item, '10')->status);
        $this->receive($item, '2', '0.0000');
        $result = $this->cost($item, '10');
        $this->assertSame(CostStatus::Costed, $result->status);
        $this->assertSame('0.0000', $result->toArray()['total_cost']);
        $this->assertSame('10', $result->toArray()['coverage'][0]['costed_quantity']);
        AvcoPool::where('item_id', $item->id)->update(['status' => 'FROZEN']);
        $this->assertSame('POOL_FROZEN', $this->cost($item, '10')->diagnostics[0]['code']);
    }

    public function test_fifo_costs_actual_demand_and_reports_partial_requirement_separately_from_selected_draw(): void
    {
        $item = $this->item('fifo', 'FIFO');
        $this->receive($item, '2', '2.0000');
        $this->travel(1)->seconds();
        $this->receive($item, '4', '5.0000');
        $partial = $this->cost($item, '10');
        $this->assertSame(CostStatus::Partial, $partial->status);
        $this->assertNull($partial->totalCost);
        $this->assertNull($partial->unitCost);
        $this->assertSame('24.0000', $partial->toArray()['known_cost']);
        $this->assertSame('4.0000', $partial->coverage[0]['unresolved_quantity']);
        $draw = app(QueryMaterialCost::class)(new MaterialCostRequest($item, '6', amountBasis: 'SELECTED_DRAW'));
        $this->assertSame(CostStatus::Costed, $draw->status);
        $this->assertSame('24.0000', $draw->toArray()['total_cost']);
        $this->assertSame('4.0000', $draw->toArray()['unit_cost']);
        $this->assertSame('2.0000', $this->cost($item, '1')->toArray()['total_cost']);
    }

    public function test_shared_scenario_does_not_reuse_layers_and_new_scenario_does(): void
    {
        $item = $this->item('fifo', 'FIFO');
        $this->receive($item, '2', '2.0000');
        $this->receive($item, '4', '5.0000');
        $query = app(QueryMaterialCost::class);
        [$first, $second] = $query->scenario(fn (CostReadSession $session): array => [
            $query->resolve(new MaterialCostRequest($item, '3'), $session),
            $query->resolve(new MaterialCostRequest($item, '4'), $session),
        ]);
        $this->assertSame('9.0000', $first->toArray()['total_cost']);
        $this->assertSame('15.0000', $second->toArray()['known_cost']);
        $this->assertSame(CostStatus::Partial, $second->status);
        $this->assertSame('9.0000', $this->cost($item, '3')->toArray()['total_cost']);
        $this->assertSame('6.0000', (string) StockLayer::where('item_id', $item->id)->sum('qty_remaining'));
    }

    public function test_warehouse_avco_is_not_blended_and_company_pool_discloses_changed_scope(): void
    {
        $warehouseItem = $this->item('site', scope: 'WAREHOUSE_ITEM');
        $this->receive($warehouseItem, '2', '7.0000');
        $this->assertSame('SCOPE_NOT_COSTABLE', $this->cost($warehouseItem, '1')->diagnostics[0]['code']);
        $query = app(QueryMaterialCost::class);
        $site = $query(new MaterialCostRequest($warehouseItem, '3', warehouseId: $this->location->warehouse_id));
        $this->assertSame('21.0000', $site->toArray()['total_cost']);
        $companyItem = $this->item('company');
        $this->receive($companyItem, '2', '8.0000');
        $company = $query(new MaterialCostRequest($companyItem, '1', warehouseId: $this->location->warehouse_id));
        $this->assertTrue($company->toArray()['scope_changed']);
        $this->assertSame('COMPANY', $company->scopeUsed['type']);
    }

    public function test_specific_requires_serial_and_copies_original_fx_evidence(): void
    {
        $item = $this->item('serial', 'SPECIFIC', tracking: 'SERIAL');
        $this->receive($item, '1', '12.0000', 'S1');
        $this->assertSame('SPECIFIC_IDENTIFICATION_REQUIRED', $this->cost($item, '1')->diagnostics[0]['code']);
        $cost = app(QueryMaterialCost::class)(new MaterialCostRequest($item, '1', locationId: $this->location->id, serialNo: 'S1'));
        $this->assertSame('12.0000', $cost->toArray()['total_cost']);
        $this->assertSame('S1', $cost->basis[0]['serial_no']);
        $this->assertSame('1.000000000', $cost->basis[0]['fx_rate']);
        $this->assertSame(CostStatus::Unknown, app(QueryMaterialCost::class)(new MaterialCostRequest($item, '1', serialNo: 'OTHER'))->status);
    }

    public function test_unsupported_time_is_unknown_and_observation_ticks_do_not_change_fingerprint(): void
    {
        $item = $this->item('now');
        $this->receive($item, '2', '3.0000');
        $result = app(QueryMaterialCost::class)(new MaterialCostRequest($item, '1', asOf: CarbonImmutable::now()->subDay()));
        $this->assertSame('AS_OF_NOT_SUPPORTED', $result->diagnostics[0]['code']);
        $this->assertNull($result->totalCost);
        $first = $this->cost($item, '1')->toArray();
        $this->travel(1)->seconds();
        $later = $this->cost($item, '1')->toArray();
        $this->assertSame($first['basis_fingerprint'], $later['basis_fingerprint']);
        $this->receive($item, '1', '9.0000');
        $this->assertNotSame($first['basis_fingerprint'], $this->cost($item, '1')->toArray()['basis_fingerprint']);
    }

    public function test_cost_adapter_requires_cost_read_not_inventory_valuation_and_refuses_limited_grants(): void
    {
        $item = $this->item('read');
        $actor = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);
        $adapter = app(ReadMaterialCost::class);
        $this->assertSame(CostStatus::Unknown, $adapter(new MaterialCostRequest($item, '1'), $actor)->status);
        DB::table('access_scope_limits')->insert(['company_id' => 1, 'user_id' => $actor->id, 'permission' => 'estimating.internal_cost.view', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(DomainInvariantException::class);
        $adapter(new MaterialCostRequest($item, '1'), $actor);
    }

    public function test_denied_cost_adapter_and_foreign_context_fail_closed(): void
    {
        $item = $this->item('denied');
        $this->expectException(DomainInvariantException::class);
        app(ReadMaterialCost::class)(new MaterialCostRequest($item, '1'), User::factory()->create());
    }

    public function test_foreign_material_is_refused_even_as_an_unscoped_model(): void
    {
        $item = $this->item('foreign');
        $this->app->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $this->expectException(DomainInvariantException::class);
        $this->cost($item, '1');
    }

    public function test_layer_rounding_residual_is_attributed_once_and_zero_demand_has_no_unit_rate(): void
    {
        $item = $this->item('round', 'FIFO');
        $this->receive($item, '1', '0.0001');
        $query = app(QueryMaterialCost::class);
        [$first, $second] = $query->scenario(fn (CostReadSession $session): array => [
            $query->resolve(new MaterialCostRequest($item, '0.5'), $session),
            $query->resolve(new MaterialCostRequest($item, '0.5'), $session),
        ]);
        $this->assertSame('0.0001', $first->toArray()['total_cost']);
        $this->assertSame('0.0000', $second->toArray()['total_cost']);
        $this->assertSame('0.0001', $this->cost($item, '1')->toArray()['total_cost']);
        $zero = $this->cost($item, '0');
        $this->assertSame(CostStatus::Costed, $zero->status);
        $this->assertNull($zero->unitCost);
    }

    public function test_cost_overflow_is_refused_instead_of_returning_an_unrepresentable_amount(): void
    {
        $item = $this->item('overflow');
        $this->receive($item, '1', '999999999999999.9999');
        $this->expectException(DomainInvariantException::class);
        $this->cost($item, '2');
    }

    public function test_quantity_precision_refuses_truncation(): void
    {
        $this->expectException(DomainInvariantException::class);
        new MaterialCostRequest($this->item('precision'), '0.0000000001');
    }

    private function item(string $code, string $method = 'AVCO', string $scope = 'COMPANY_ITEM', string $tracking = 'NONE'): Item
    {
        return Item::create(['code' => $code, 'name' => $code, 'type' => 'RAW', 'stock_uom_id' => $this->uom->id,
            'category_id' => $this->category->id, 'is_stocked' => true, 'valuation_method' => $method,
            'avco_pool_scope' => $method === 'AVCO' ? $scope : null, 'tracking' => $tracking, 'inventory_account_id' => $this->account->id]);
    }

    private function receive(Item $item, string $qty, string $cost, ?string $serial = null): void
    {
        app(PostInventoryMovement::class)(['item_id' => $item->id, 'location_id' => $this->location->id,
            'qty' => $qty, 'uom_id' => $this->uom->id, 'unit_cost' => $cost, 'base_unit_cost' => $cost,
            'currency_id' => Company::findOrFail(1)->base_currency_id, 'fx_rate' => '1',
            'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->sourceId, 'moved_at' => now(), 'serial_no' => $serial]);
    }

    private function cost(Item $item, string $quantity): CostResolution
    {
        return app(QueryMaterialCost::class)(new MaterialCostRequest($item, $quantity));
    }
}

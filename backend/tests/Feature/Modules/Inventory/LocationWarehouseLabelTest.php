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
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A location's code is unique only within its warehouse, so two warehouses may both have a `stock`
 * location — and then a transfer between them read "stock → stock" on every screen that labelled a
 * location by its code. Every location the API embeds therefore carries its warehouse, which is
 * what the UI's one shared label is built from.
 */
class LocationWarehouseLabelTest extends TestCase
{
    use RefreshDatabase;

    private Location $main;

    private Location $karachi;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::create(['code' => 'ea', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'raw', 'name' => 'Raw', 'default_valuation_method' => 'AVCO']);
        $this->item = Item::create([
            'code' => 'paint', 'name' => 'Paint', 'type' => 'RAW', 'stock_uom_id' => $uom->id, 'category_id' => $category->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'avco_pool_scope' => AvcoPoolScope::CompanyItem,
            'inventory_account_id' => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset])->id,
        ]);
        // Two warehouses whose only location is coded identically.
        $this->main = $this->warehouse('main', 'Main Warehouse');
        $this->karachi = $this->warehouse('khi', 'Karachi Depot');
        $this->assertSame($this->main->code, $this->karachi->code);

        app(PostInventoryMovement::class)([
            'item_id' => $this->item->id, 'location_id' => $this->main->id, 'qty' => '10.0000', 'uom_id' => $uom->id,
            'moved_at' => now()->subDay(), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '5.0000', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => '5.0000',
        ]);
        app(PostInventoryMovement::class)->transfer([
            'item_id' => $this->item->id, 'from_location_id' => $this->main->id, 'to_location_id' => $this->karachi->id,
            'qty' => '4.0000', 'uom_id' => $uom->id, 'moved_at' => now(), 'source_type' => 'TEST_TRANSFER', 'source_id' => 2,
        ]);
    }

    public function test_a_transfer_between_identically_coded_locations_says_which_warehouse_each_end_is_in(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/stock-moves?filter[source_type]=TEST_TRANSFER&include=from_location,to_location')
            ->assertOk();

        // The codes alone cannot tell the two ends apart — the warehouse can.
        $response->assertJsonPath('data.0.from_location.code', 'stock');
        $response->assertJsonPath('data.0.to_location.code', 'stock');
        $response->assertJsonPath('data.0.from_location.warehouse.code', 'main');
        $response->assertJsonPath('data.0.to_location.warehouse.code', 'khi');
    }

    public function test_an_endpoint_that_only_includes_the_location_still_carries_its_warehouse(): void
    {
        // Stock layers include `location` plainly (no nested warehouse include), like the override,
        // recalculation, count-line and cutover-exception resources.
        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/stock-layers?include=location')->assertOk();

        $warehouses = collect($response->json('data'))->map(fn (array $layer): string => $layer['location']['warehouse']['code'])->unique()->sort()->values()->all();
        $this->assertSame(['khi', 'main'], $warehouses);
    }

    public function test_the_location_list_carries_the_warehouse_without_asking_for_it(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/v1/locations')->assertOk();

        $byWarehouse = collect($response->json('data'))->mapWithKeys(fn (array $row): array => [$row['warehouse']['code'] => $row['code']]);
        $this->assertSame(['main' => 'stock', 'khi' => 'stock'], $byWarehouse->all());
    }

    public function test_the_warehouse_queries_do_not_grow_with_the_number_of_rows(): void
    {
        $user = User::factory()->create();
        $uom = $this->item->stockUom;
        $url = '/api/v1/stock-moves?filter[source_type]=TEST_TRANSFER&include=from_location,to_location&per_page=50';
        $warehouseQueries = function () use ($user, $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($user)->getJson($url)->assertOk();
            $count = collect(DB::getQueryLog())->filter(fn (array $q): bool => preg_match('/from "warehouses"/i', $q['query']) === 1)->count();
            DB::disableQueryLog();

            return $count;
        };

        $withOneMove = $warehouseQueries();
        for ($i = 3; $i <= 8; $i++) {
            app(PostInventoryMovement::class)->transfer([
                'item_id' => $this->item->id, 'from_location_id' => $this->main->id, 'to_location_id' => $this->karachi->id,
                'qty' => '0.5000', 'uom_id' => $uom->id, 'moved_at' => now(), 'source_type' => 'TEST_TRANSFER', 'source_id' => $i,
            ]);
        }
        $withSevenMoves = $warehouseQueries();

        // Seven moves × two locations would add fourteen queries if each location loaded its
        // warehouse lazily; eager loading keeps the count fixed whatever the page size.
        $this->assertSame($withOneMove, $withSevenMoves);
    }

    private function warehouse(string $code, string $name): Location
    {
        return app(CreateWarehouse::class)(['code' => $code, 'name' => $name, 'location_code' => 'stock', 'location_name' => 'Stock'])->locations()->sole();
    }
}

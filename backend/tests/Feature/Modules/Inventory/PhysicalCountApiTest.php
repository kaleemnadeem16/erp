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
use App\Modules\Inventory\Support\ItemTracking;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * The physical count HTTP surface — first exposed per
 * docs/phases/phase-2-purchasing-stock.md#close-out. The workflow rules
 * themselves (variance posting, separation of duties, immutability) are
 * covered at the Action level by PhysicalCountTest; this only proves the
 * controller/request layer wires them correctly.
 */
class PhysicalCountApiTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;

    private int $locationId;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['inventory.physical_count.create', 'inventory.physical_count.count', 'inventory.physical_count.post', 'inventory.valuation.view', 'inventory.stock.view'] as $permission) {
            Permission::findOrCreate($permission);
        }

        $currency = Currency::query()->create(['code' => 'PKR', 'name' => 'Pakistan Rupee', 'symbol' => 'Rs', 'decimal_places' => 2]);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        $uom = Uom::query()->create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1.000000000', 'is_base' => true]);
        $account = ChartOfAccount::query()->create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset]);
        $category = Category::query()->create(['code' => 'stock', 'name' => 'Stock', 'default_valuation_method' => ValuationMethod::Avco, 'inventory_account_id' => $account->id]);
        $warehouse = app(CreateWarehouse::class)(['code' => 'MAIN', 'name' => 'Main warehouse', 'is_default' => true, 'location_code' => 'STOCK', 'location_name' => 'Stock'], PurchasingActor::make(['inventory.warehouse.manage']));
        $this->warehouseId = $warehouse->id;
        $this->locationId = (int) Location::query()->where('code', 'stock')->value('id');

        $this->item = Item::query()->create([
            'code' => 'sku-cnt', 'name' => 'Counted item', 'type' => ItemType::Raw, 'stock_uom_id' => $uom->id,
            'is_stocked' => true, 'valuation_method' => ValuationMethod::Avco, 'tracking' => ItemTracking::None,
            'avco_pool_scope' => 'COMPANY_ITEM', 'category_id' => $category->id,
        ]);
        app(PostInventoryMovement::class)([
            'item_id' => $this->item->id, 'location_id' => $this->locationId, 'qty' => '10.0000', 'uom_id' => $uom->id,
            'moved_at' => now()->subDays(5), 'source_type' => 'TEST_RECEIPT', 'source_id' => 1,
            'unit_cost' => '5.0000', 'currency_id' => $currency->id, 'fx_rate' => '1.000000000', 'base_unit_cost' => '5.0000',
        ]);
    }

    /** @param string|array<int, string> $permissions */
    private function permittedUser(string|array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/physical-counts')->assertUnauthorized();
    }

    public function test_full_workflow_create_add_line_count_and_post(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');

        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');

        $lineId = $this->actingAs($creator)
            ->postJson("/api/v1/physical-counts/{$countId}/lines", ['item_id' => $this->item->id, 'location_id' => $this->locationId])
            ->assertCreated()->assertJsonPath('data.book_qty', '10.0000')->json('data.id');

        $this->actingAs($counter)->postJson("/api/v1/physical-count-lines/{$lineId}/count", ['counted_qty' => '8.0000'])
            ->assertOk()->assertJsonPath('data.counted_qty', '8.0000');

        $this->actingAs($poster)->postJson("/api/v1/physical-counts/{$countId}/post", ['found_costs' => []])
            ->assertOk()
            ->assertJsonPath('data.status', 'POSTED')
            ->assertJsonPath('data.posted_by', $poster->id);

        $show = $this->actingAs($poster)->getJson("/api/v1/physical-counts/{$countId}?include=lines,warehouse")
            ->assertOk()->assertJsonPath('data.warehouse.id', $this->warehouseId);
        $show->assertJsonPath('data.lines.0.posted_stock_move_id', fn ($id) => $id !== null);
    }

    public function test_positive_variance_requires_a_found_cost_to_post(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');

        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');
        $lineId = $this->actingAs($creator)
            ->postJson("/api/v1/physical-counts/{$countId}/lines", ['item_id' => $this->item->id, 'location_id' => $this->locationId])
            ->json('data.id');
        $this->actingAs($counter)->postJson("/api/v1/physical-count-lines/{$lineId}/count", ['counted_qty' => '12.0000'])->assertOk();

        $this->actingAs($poster)->postJson("/api/v1/physical-counts/{$countId}/post", ['found_costs' => []])
            ->assertUnprocessable();

        $this->actingAs($poster)->postJson("/api/v1/physical-counts/{$countId}/post", [
            'found_costs' => [['physical_count_line_id' => $lineId, 'unit_cost' => '6.0000']],
        ])->assertOk()->assertJsonPath('data.status', 'POSTED');

        $this->assertSame('6.0000', $this->getJson("/api/v1/physical-counts/{$countId}?include=lines")
            ->json('data.lines.0.found_unit_cost'));
    }

    public function test_found_cost_is_hidden_from_quantity_readers_but_visible_to_posters_and_valuation_auditors(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');
        $auditor = $this->permittedUser('inventory.valuation.view');

        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');
        $lineId = $this->actingAs($creator)->postJson("/api/v1/physical-counts/{$countId}/lines", [
            'item_id' => $this->item->id, 'location_id' => $this->locationId,
        ])->json('data.id');
        $this->actingAs($counter)->postJson("/api/v1/physical-count-lines/{$lineId}/count", ['counted_qty' => '12.0000'])->assertOk();

        $this->actingAs($poster)->postJson("/api/v1/physical-counts/{$countId}/post", [
            'found_costs' => [['physical_count_line_id' => $lineId, 'unit_cost' => '6.0000']],
        ])->assertOk()->assertJsonPath('data.lines.0.found_unit_cost', '6.0000');

        foreach ([$creator, $counter, User::factory()->create()] as $reader) {
            $this->actingAs($reader)->getJson("/api/v1/physical-counts/{$countId}?include=lines")
                ->assertOk()->assertJsonPath('data.lines.0.counted_qty', '12.0000')
                ->assertJsonMissingPath('data.lines.0.found_unit_cost');
            $this->actingAs($reader)->getJson('/api/v1/physical-counts?include=lines')
                ->assertOk()->assertJsonMissingPath('data.0.lines.0.found_unit_cost');
        }

        foreach ([$poster, $auditor] as $reader) {
            $this->actingAs($reader)->getJson("/api/v1/physical-counts/{$countId}?include=lines")
                ->assertOk()->assertJsonPath('data.lines.0.found_unit_cost', '6.0000');
        }
    }

    public function test_poster_cannot_be_the_creator(): void
    {
        $creator = $this->permittedUser(['inventory.physical_count.create', 'inventory.physical_count.post']);
        $counter = $this->permittedUser('inventory.physical_count.count');

        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');
        $lineId = $this->actingAs($creator)
            ->postJson("/api/v1/physical-counts/{$countId}/lines", ['item_id' => $this->item->id, 'location_id' => $this->locationId])
            ->json('data.id');
        $this->actingAs($counter)->postJson("/api/v1/physical-count-lines/{$lineId}/count", ['counted_qty' => '10.0000'])->assertOk();

        $this->actingAs($creator)->postJson("/api/v1/physical-counts/{$countId}/post", ['found_costs' => []])
            ->assertUnprocessable();
    }

    public function test_a_draft_can_be_cancelled(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');

        $this->actingAs($creator)->postJson("/api/v1/physical-counts/{$countId}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_store_validates_warehouse_and_rejects_an_unpermitted_actor(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');

        $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('warehouse_id');

        // Refused at the request layer (403) before validation; the Action keeps its own check for non-HTTP callers.
        $this->actingAs(User::factory()->create())->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->assertForbidden();
    }

    public function test_index_filters_by_warehouse_and_status(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');
        $this->actingAs($creator)->postJson("/api/v1/physical-counts/{$countId}/cancel")->assertOk();
        $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->assertCreated();

        $this->actingAs($creator)->getJson('/api/v1/physical-counts?filter[status]=CANCELLED')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $countId);

        $this->getJson('/api/v1/physical-counts?filter[status]=BOGUS')
            ->assertUnprocessable()->assertJsonValidationErrors('filter.status');
    }

    public function test_another_companys_physical_count_is_not_visible(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs(User::factory()->create())->getJson("/api/v1/physical-counts/{$countId}")->assertNotFound();
    }

    public function test_a_counter_counts_blind_while_reviewers_and_stock_viewers_see_the_book_quantity(): void
    {
        $creator = $this->permittedUser('inventory.physical_count.create');
        $counter = $this->permittedUser('inventory.physical_count.count');
        $poster = $this->permittedUser('inventory.physical_count.post');
        $stockViewer = $this->permittedUser(['inventory.physical_count.count', 'inventory.stock.view']);

        $countId = $this->actingAs($creator)->postJson('/api/v1/physical-counts', ['warehouse_id' => $this->warehouseId])->json('data.id');
        $lineId = $this->actingAs($creator)
            ->postJson("/api/v1/physical-counts/{$countId}/lines", ['item_id' => $this->item->id, 'location_id' => $this->locationId])
            ->assertCreated()->assertJsonPath('data.book_qty', '10.0000')->json('data.id');

        // The blind counter never receives the figure — not on the count, the list, or their own count response.
        $blind = $this->actingAs($counter)->getJson("/api/v1/physical-counts/{$countId}?include=lines")->assertOk();
        $this->assertStringNotContainsString('book_qty', $blind->getContent());
        $this->assertStringNotContainsString('10.0000', $blind->getContent());
        $response = $this->postJson("/api/v1/physical-count-lines/{$lineId}/count", ['counted_qty' => '8.0000'])->assertOk()->assertJsonPath('data.counted_qty', '8.0000');
        $this->assertStringNotContainsString('book_qty', $response->getContent());
        $this->assertStringNotContainsString('book_qty', $this->getJson('/api/v1/physical-counts')->getContent());

        // Whoever sets up or posts a count, or may see stock positions, does.
        foreach ([$creator, $poster, $stockViewer] as $reader) {
            $this->actingAs($reader)->getJson("/api/v1/physical-counts/{$countId}?include=lines")->assertOk()->assertJsonPath('data.lines.0.book_qty', '10.0000');
        }
    }
}

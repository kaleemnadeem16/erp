<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * FIFO/SPECIFIC receipt layers' first HTTP surface — read-only, per
 * docs/phases/phase-2-purchasing-stock.md#close-out. PostInventoryMovement
 * and the recalculation executors remain the only write paths; there is
 * deliberately no store/update/destroy here to test.
 */
class StockLayerApiTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    private Location $warehouseA;

    private Location $warehouseB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture(ValuationMethod::Fifo);
        $this->warehouseA = $this->location('a');
        $this->warehouseB = $this->location('b');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/stock-layers')->assertUnauthorized();
    }

    public function test_index_cursor_paginates_newest_first_by_default(): void
    {
        $user = User::factory()->create();
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $this->receive($this->warehouseA, '3', '12', '2026-01-02', 2);
        $this->receive($this->warehouseA, '1', '15', '2026-01-03', 3);

        $response = $this->actingAs($user)->getJson('/api/v1/stock-layers?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.source_id', 3);
        $response->assertJsonPath('data.1.source_id', 2);
        $nextCursor = $response->json('meta.next_cursor');
        $this->assertNotNull($nextCursor);

        $second = $this->getJson('/api/v1/stock-layers?per_page=2&cursor='.$nextCursor);
        $second->assertOk();
        $second->assertJsonCount(1, 'data');
        $second->assertJsonPath('data.0.source_id', 1);
    }

    public function test_index_filters_by_item_and_location(): void
    {
        $user = User::factory()->create();
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $this->receive($this->warehouseB, '2', '10', '2026-01-05', 2);

        $this->actingAs($user);

        $this->getJson('/api/v1/stock-layers?filter[item_id]='.$this->item->id)
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/stock-layers?filter[location_id]='.$this->warehouseB->id)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_id', 2);
    }

    public function test_index_filters_open_layers(): void
    {
        $user = User::factory()->create();
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $this->receive($this->warehouseA, '5', '12', '2026-01-02', 2);
        $this->issue($this->warehouseA, '5', '2026-01-03', 3);

        $this->actingAs($user);

        $this->getJson('/api/v1/stock-layers?filter[open]=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_id', 2);

        $this->getJson('/api/v1/stock-layers?filter[open]=0')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source_id', 1);
    }

    public function test_index_rejects_invalid_filters_sort_and_include(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/stock-layers?sort=unit_cost')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');

        $this->getJson('/api/v1/stock-layers?include=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('include');

        $this->getJson('/api/v1/stock-layers?per_page=101')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_show_can_include_item_and_location(): void
    {
        $user = User::factory()->create();
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $layer = StockLayer::query()->sole();

        $response = $this->actingAs($user)->getJson("/api/v1/stock-layers/{$layer->id}?include=item,location");

        $response->assertOk();
        $response->assertJsonPath('data.qty_remaining', '5.0000');
        $response->assertJsonPath('data.item.code', 'item');
        $response->assertJsonPath('data.location.code', 'a');
        $response->assertJsonMissingPath('data.unit_cost');
        $response->assertJsonMissingPath('data.base_unit_cost');
    }

    public function test_valuation_viewer_sees_layer_costs(): void
    {
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $layer = StockLayer::query()->sole();
        $viewer = PermissionedActor::grant(User::factory()->create(), ['inventory.valuation.view']);

        $this->actingAs($viewer)->getJson("/api/v1/stock-layers/{$layer->id}")
            ->assertOk()
            ->assertJsonPath('data.unit_cost', '10.0000')
            ->assertJsonPath('data.base_unit_cost', '10.0000');
    }

    public function test_another_companys_stock_layer_is_not_visible(): void
    {
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $layer = StockLayer::query()->sole();

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs(User::factory()->create())->getJson("/api/v1/stock-layers/{$layer->id}")->assertNotFound();
    }
}

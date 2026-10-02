<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Modules\Inventory\Concerns\BuildsOutboundRecalculation;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * An item's current AVCO balance, first HTTP surface — read-only, per
 * docs/phases/phase-2-purchasing-stock.md#close-out.
 * App\Modules\Inventory\Actions\Movements\AvcoPoolManager remains the only
 * write path; there is deliberately no store/update/destroy here to test.
 */
class AvcoPoolApiTest extends TestCase
{
    use BuildsOutboundRecalculation, RefreshDatabase;

    private Location $warehouseA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture(ValuationMethod::Avco);
        $this->warehouseA = $this->location('a');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/avco-pools')->assertUnauthorized();
    }

    public function test_index_reflects_the_current_converged_balance(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.valuation.view']);
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $this->receive($this->warehouseA, '5', '20', '2026-01-02', 2);

        $response = $this->actingAs($user)->getJson('/api/v1/avco-pools');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.qty_on_hand', '10.0000');
        $response->assertJsonPath('data.0.avg_unit_cost', '15.0000');
        $response->assertJsonPath('data.0.status', 'ACTIVE');
    }

    public function test_ordinary_reader_sees_pool_quantity_but_not_value_or_rates(): void
    {
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $pool = AvcoPool::query()->sole();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/avco-pools')
            ->assertOk()
            ->assertJsonPath('data.0.qty_on_hand', '5.0000')
            ->assertJsonMissingPath('data.0.inventory_value')
            ->assertJsonMissingPath('data.0.avg_unit_cost');

        $this->getJson("/api/v1/avco-pools/{$pool->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.inventory_value')
            ->assertJsonMissingPath('data.last_valid_avg_unit_cost');
    }

    public function test_index_filters_by_item_and_status(): void
    {
        $user = User::factory()->create();
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);

        $this->actingAs($user);

        $this->getJson('/api/v1/avco-pools?filter[item_id]='.$this->item->id)
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/avco-pools?filter[status]=FROZEN')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/avco-pools?filter[status]=BOGUS')
            ->assertUnprocessable()->assertJsonValidationErrors('filter.status');
    }

    public function test_index_rejects_invalid_sort_and_include(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/avco-pools?sort=qty_on_hand')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');

        $this->getJson('/api/v1/avco-pools?include=unknown')
            ->assertUnprocessable()->assertJsonValidationErrors('include');
    }

    public function test_show_can_include_item_and_warehouse(): void
    {
        $user = User::factory()->create();
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $pool = AvcoPool::query()->sole();

        $response = $this->actingAs($user)->getJson("/api/v1/avco-pools/{$pool->id}?include=item");

        $response->assertOk();
        $response->assertJsonPath('data.item.code', 'item');
        $response->assertJsonPath('data.scope', 'COMPANY_ITEM');
    }

    public function test_another_companys_avco_pool_is_not_visible(): void
    {
        $this->receive($this->warehouseA, '5', '10', '2026-01-01', 1);
        $pool = AvcoPool::query()->sole();

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs(User::factory()->create())->getJson("/api/v1/avco-pools/{$pool->id}")->assertNotFound();
    }
}

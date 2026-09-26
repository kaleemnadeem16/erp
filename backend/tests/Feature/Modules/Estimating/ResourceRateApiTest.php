<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ResolveResourceRate;
use App\Modules\Estimating\Models\ResourceRate;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResourceRateApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $hour;

    private Item $electrician;

    private Item $paintItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hour = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'RESOURCES', 'name' => 'Resources', 'default_valuation_method' => 'AVCO']);
        $this->electrician = Item::create([
            'code' => 'demo-electrician', 'name' => 'Electrician', 'type' => ItemType::Labour,
            'stock_uom_id' => $this->hour->id, 'category_id' => $category->id,
        ]);
        $kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $this->paintItem = Item::create([
            'code' => 'demo-not-labour', 'name' => 'Not a labour item', 'type' => ItemType::Raw,
            'stock_uom_id' => $kg->id, 'category_id' => $category->id,
        ]);
    }

    private function actingAsCompany(int $companyId): void
    {
        $this->app->instance(CompanyContext::class, new class($companyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/resource-rates')->assertUnauthorized();
    }

    public function test_a_rate_can_be_created_for_a_labour_item(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/resource-rates', [
            'item_id' => $this->electrician->id, 'rate' => '20.000000',
            'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-01-01',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.item_id', $this->electrician->id)
            ->assertJsonPath('data.rate', '20.000000')
            ->assertJsonPath('data.effective_from', '2026-01-01');
    }

    public function test_a_non_labour_or_plant_item_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/resource-rates', [
            'item_id' => $this->paintItem->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('item_id');
    }

    public function test_a_cross_company_item_is_rejected(): void
    {
        $user = User::factory()->create();
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompanyId);
        $otherHour = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $otherCategory = Category::create(['code' => 'RESOURCES', 'name' => 'Resources', 'default_valuation_method' => 'AVCO']);
        $otherItem = Item::create([
            'code' => 'demo-electrician', 'name' => 'Electrician', 'type' => ItemType::Labour,
            'stock_uom_id' => $otherHour->id, 'category_id' => $otherCategory->id,
        ]);
        $this->actingAsCompany(1);

        $this->actingAs($user)->postJson('/api/v1/resource-rates', [
            'item_id' => $otherItem->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('item_id');
    }

    public function test_duplicate_item_and_effective_date_is_rejected(): void
    {
        $user = User::factory()->create();
        ResourceRate::create([
            'item_id' => $this->electrician->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($user)->postJson('/api/v1/resource-rates', [
            'item_id' => $this->electrician->id, 'rate' => '25', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('effective_from');
    }

    public function test_a_later_dated_rate_for_the_same_item_is_allowed(): void
    {
        $user = User::factory()->create();
        ResourceRate::create([
            'item_id' => $this->electrician->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($user)->postJson('/api/v1/resource-rates', [
            'item_id' => $this->electrician->id, 'rate' => '23', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-07-01',
        ])->assertCreated();
    }

    public function test_resolver_picks_the_most_recent_rate_on_or_before_the_target_date(): void
    {
        ResourceRate::create(['item_id' => $this->electrician->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-01-01']);
        ResourceRate::create(['item_id' => $this->electrician->id, 'rate' => '23', 'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-07-01']);

        $resolve = new ResolveResourceRate;

        $this->assertSame('20.000000', $resolve($this->electrician, Carbon::parse('2026-03-01'))->rate);
        $this->assertSame('23.000000', $resolve($this->electrician, Carbon::parse('2026-08-01'))->rate);
        $this->assertNull($resolve($this->electrician, Carbon::parse('2025-12-31')));
    }

    public function test_a_rate_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $rate = ResourceRate::create([
            'item_id' => $this->electrician->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id,
            'effective_from' => '2026-01-01',
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/resource-rates/{$rate->id}")->assertNoContent();
        $this->assertDatabaseMissing('resource_rates', ['id' => $rate->id]);
    }

    public function test_index_filters_by_item(): void
    {
        $user = User::factory()->create();
        ResourceRate::create(['item_id' => $this->electrician->id, 'rate' => '20', 'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-01-01']);

        $this->actingAs($user)->getJson("/api/v1/resource-rates?filter[item_id]={$this->electrician->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/resource-rates?filter[item_id]=999999')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_another_companys_rate_is_not_visible(): void
    {
        $user = User::factory()->create();
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompanyId);
        $otherHour = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $otherCategory = Category::create(['code' => 'RESOURCES', 'name' => 'Resources', 'default_valuation_method' => 'AVCO']);
        $otherItem = Item::create([
            'code' => 'demo-electrician', 'name' => 'Electrician', 'type' => ItemType::Labour,
            'stock_uom_id' => $otherHour->id, 'category_id' => $otherCategory->id,
        ]);
        $otherRate = ResourceRate::create(['item_id' => $otherItem->id, 'rate' => '20', 'rate_uom_id' => $otherHour->id, 'effective_from' => '2026-01-01']);
        $this->actingAsCompany(1);

        $this->actingAs($user)->getJson("/api/v1/resource-rates/{$otherRate->id}")->assertNotFound();
    }
}

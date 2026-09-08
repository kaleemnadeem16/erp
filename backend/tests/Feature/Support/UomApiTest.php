<?php

namespace Tests\Feature\Support;

use App\Models\Uom;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UomApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/uoms')->assertUnauthorized();
    }

    public function test_index_lists_filters_sorts_and_paginates(): void
    {
        Uom::create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1', 'is_base' => true]);
        Uom::create(['code' => 'KG', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        Uom::create(['code' => 'G', 'name' => 'Gram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '0.001', 'is_base' => false]);
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/uoms')
            ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);

        $this->actingAs($user)->getJson('/api/v1/uoms?filter[dimension]=MASS')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($user)->getJson('/api/v1/uoms?filter[search]=kilo')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'KG');

        $this->actingAs($user)->getJson('/api/v1/uoms?sort=-code')
            ->assertOk()->assertJsonPath('data.0.code', 'KG');

        $this->actingAs($user)->getJson('/api/v1/uoms?sort=unknown')->assertUnprocessable();
    }

    public function test_show_returns_one_uom(): void
    {
        $uom = Uom::create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1', 'is_base' => true]);

        $this->actingAs(User::factory()->create())->getJson("/api/v1/uoms/{$uom->id}")
            ->assertOk()->assertJsonPath('data.code', 'EA')->assertJsonPath('data.dimension', 'COUNT');
    }

    public function test_another_companys_uom_is_not_visible(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompany) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherUom = Uom::create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1', 'is_base' => true]);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $this->actingAs(User::factory()->create())->getJson("/api/v1/uoms/{$otherUom->id}")->assertNotFound();
    }
}

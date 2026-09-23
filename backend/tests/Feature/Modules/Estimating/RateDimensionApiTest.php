<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\User;
use App\Modules\Estimating\Models\RateDimension;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RateDimensionApiTest extends TestCase
{
    use RefreshDatabase;

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
        $this->getJson('/api/v1/rate-dimensions')->assertUnauthorized();
    }

    public function test_a_dimension_and_its_values_can_be_created_and_listed(): void
    {
        $user = User::factory()->create();

        $dimension = $this->actingAs($user)->postJson('/api/v1/rate-dimensions', [
            'code' => 'SURFACE', 'name' => 'Surface Type', 'is_required' => true,
        ])->assertCreated()->assertJsonPath('data.code', 'SURFACE')->json('data');

        $this->postJson("/api/v1/rate-dimensions/{$dimension['id']}/values", [
            'code' => 'SMOOTH', 'name' => 'Smooth plaster',
        ])->assertCreated()->assertJsonPath('data.name', 'Smooth plaster');

        $this->postJson("/api/v1/rate-dimensions/{$dimension['id']}/values", [
            'code' => 'ROUGH', 'name' => 'Rough plaster',
        ])->assertCreated();

        $this->getJson("/api/v1/rate-dimensions/{$dimension['id']}/values")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/rate-dimensions?include=values')
            ->assertOk()->assertJsonCount(2, 'data.0.values');
    }

    public function test_code_must_be_unique_per_company(): void
    {
        $user = User::factory()->create();
        RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);

        $this->actingAs($user)->postJson('/api/v1/rate-dimensions', [
            'code' => 'SURFACE', 'name' => 'Duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_second_companys_dimension_with_the_same_code_is_allowed(): void
    {
        $user = User::factory()->create();
        RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);

        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompany);

        $this->actingAs($user)->postJson('/api/v1/rate-dimensions', [
            'code' => 'SURFACE', 'name' => 'Surface Type Too',
        ])->assertCreated();
    }

    public function test_deleting_a_dimension_with_values_is_blocked(): void
    {
        $user = User::factory()->create();
        $dimension = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);
        $dimension->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster']);

        $this->actingAs($user)->deleteJson("/api/v1/rate-dimensions/{$dimension->id}")
            ->assertStatus(409);
    }

    public function test_a_value_code_must_be_unique_within_its_dimension_but_not_across_dimensions(): void
    {
        $user = User::factory()->create();
        $surface = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);
        $thickness = RateDimension::create(['code' => 'THICKNESS', 'name' => 'Thickness']);
        $surface->values()->create(['code' => 'A', 'name' => 'Value A']);

        $this->actingAs($user)->postJson("/api/v1/rate-dimensions/{$surface->id}/values", [
            'code' => 'A', 'name' => 'Duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson("/api/v1/rate-dimensions/{$thickness->id}/values", [
            'code' => 'A', 'name' => 'Allowed on a different dimension',
        ])->assertCreated();
    }
}

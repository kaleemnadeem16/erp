<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Estimating\Actions\ArchiveRateDimension;
use App\Modules\Estimating\Actions\CreateRateDimension;
use App\Modules\Estimating\Actions\CreateRateDimensionValue;
use App\Modules\Estimating\Actions\DeleteRateDimensionValue;
use App\Modules\Estimating\Actions\UpdateRateDimension;
use App\Modules\Estimating\Actions\UpdateRateDimensionValue;
use App\Modules\Estimating\Models\RateDimension;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
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
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.rate-dimension.manage']);

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
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.rate-dimension.manage']);
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
        PermissionedActor::grant($user, ['estimating.rate-dimension.manage'], $otherCompany);

        $this->actingAs($user)->postJson('/api/v1/rate-dimensions', [
            'code' => 'SURFACE', 'name' => 'Surface Type Too',
        ])->assertCreated();
    }

    public function test_deleting_a_dimension_with_values_is_blocked(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.rate-dimension.manage']);
        $dimension = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);
        $dimension->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster']);

        $this->actingAs($user)->deleteJson("/api/v1/rate-dimensions/{$dimension->id}")
            ->assertStatus(409);
    }

    public function test_a_value_code_must_be_unique_within_its_dimension_but_not_across_dimensions(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.rate-dimension.manage']);
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

    public function test_dimension_and_value_writes_require_the_manage_capability_but_reads_remain_available(): void
    {
        $dimension = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);
        $value = $dimension->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster']);
        $reader = User::factory()->create();

        $this->actingAs($reader)->getJson('/api/v1/rate-dimensions')->assertOk();
        $this->getJson("/api/v1/rate-dimensions/{$dimension->id}")->assertOk();
        $this->getJson("/api/v1/rate-dimensions/{$dimension->id}/values")->assertOk();
        $this->postJson('/api/v1/rate-dimensions', ['code' => 'THICKNESS', 'name' => 'Thickness'])->assertForbidden();
        $this->patchJson("/api/v1/rate-dimensions/{$dimension->id}", ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson("/api/v1/rate-dimensions/{$dimension->id}")->assertForbidden();
        $this->postJson("/api/v1/rate-dimensions/{$dimension->id}/values", ['code' => 'ROUGH', 'name' => 'Rough'])->assertForbidden();
        $this->patchJson("/api/v1/rate-dimension-values/{$value->id}", ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson("/api/v1/rate-dimension-values/{$value->id}")->assertForbidden();

        $this->assertSame('Surface Type', $dimension->fresh()->name);
        $this->assertSame('Smooth plaster', $value->fresh()->name);
        $this->assertDatabaseMissing('rate_dimensions', ['code' => 'THICKNESS']);
        $this->assertDatabaseMissing('rate_dimension_values', ['code' => 'ROUGH']);
    }

    public function test_a_manager_can_update_and_delete_values_and_the_dimension(): void
    {
        $manager = PermissionedActor::grant(User::factory()->create(), ['estimating.rate-dimension.manage']);
        $dimension = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);
        $value = $dimension->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster']);

        $this->actingAs($manager)->patchJson("/api/v1/rate-dimensions/{$dimension->id}", ['name' => 'Surface'])->assertOk();
        $this->patchJson("/api/v1/rate-dimension-values/{$value->id}", ['name' => 'Smooth'])->assertOk();
        $this->assertSame('Surface', $dimension->fresh()->name);
        $this->assertSame('Smooth', $value->fresh()->name);
        $this->deleteJson("/api/v1/rate-dimension-values/{$value->id}")->assertNoContent();
        $this->deleteJson("/api/v1/rate-dimensions/{$dimension->id}")->assertNoContent();
    }

    public function test_all_rate_dimension_actions_reject_an_unprivileged_non_http_caller(): void
    {
        $reader = User::factory()->create();
        $dimension = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type']);
        $value = $dimension->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster']);

        $actions = [
            fn () => app(CreateRateDimension::class)(['code' => 'ROUGH', 'name' => 'Rough'], $reader),
            fn () => app(UpdateRateDimension::class)($dimension, ['name' => 'Changed'], $reader),
            fn () => app(ArchiveRateDimension::class)($dimension, $reader),
            fn () => app(CreateRateDimensionValue::class)($dimension, ['code' => 'ROUGH', 'name' => 'Rough'], $reader),
            fn () => app(UpdateRateDimensionValue::class)($value, ['name' => 'Changed'], $reader),
            fn () => app(DeleteRateDimensionValue::class)($value, $reader),
        ];

        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('An unprivileged Action caller was accepted.');
            } catch (DomainInvariantException $exception) {
                $this->assertStringContainsString('not permitted', $exception->getMessage());
            }
        }

        $this->assertSame('Surface Type', $dimension->fresh()->name);
        $this->assertSame('Smooth plaster', $value->fresh()->name);
        $this->assertDatabaseMissing('rate_dimensions', ['code' => 'ROUGH']);
        $this->assertDatabaseMissing('rate_dimension_values', ['code' => 'ROUGH']);
    }
}

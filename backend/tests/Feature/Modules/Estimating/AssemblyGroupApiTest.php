<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyGroup;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssemblyGroupApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $each;

    protected function setUp(): void
    {
        parent::setUp();
        $this->each = Uom::create(['code' => 'EA', 'name' => 'Each', 'dimension' => UomDimension::Count, 'factor_to_base' => '1']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/assembly-groups')->assertUnauthorized();
    }

    public function test_a_group_can_be_created_updated_and_fetched(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/assembly-groups', [
            'code' => 'FABRICATION', 'name' => 'Fabrication',
        ]);
        $response->assertCreated()->assertJsonPath('data.code', 'FABRICATION');
        $id = $response->json('data.id');

        $this->patchJson("/api/v1/assembly-groups/{$id}", ['name' => 'Fabrication work'])
            ->assertOk()->assertJsonPath('data.name', 'Fabrication work');

        $this->getJson("/api/v1/assembly-groups/{$id}")
            ->assertOk()->assertJsonPath('data.code', 'FABRICATION');
    }

    public function test_code_must_be_unique_per_company(): void
    {
        $user = User::factory()->create();
        AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);

        $this->actingAs($user)->postJson('/api/v1/assembly-groups', [
            'code' => 'FABRICATION', 'name' => 'Duplicate',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_second_companys_group_with_the_same_code_is_allowed(): void
    {
        $user = User::factory()->create();
        AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);

        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->instance(CompanyContext::class, new class($otherCompany) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs($user)->postJson('/api/v1/assembly-groups', [
            'code' => 'FABRICATION', 'name' => 'Fabrication Too',
        ])->assertCreated();
    }

    public function test_another_companys_group_is_not_visible(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Other Co', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });
        $otherCompanyGroup = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);
        $this->app->instance(CompanyContext::class, new CompanyContext);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/assembly-groups/{$otherCompanyGroup->id}")->assertNotFound();
    }

    /**
     * assembly_groups.id has no ON DELETE CASCADE from assemblies.assembly_group_id
     * (RESTRICT is Postgres's default) — proves the FK violation surfaces as a
     * clean 409 via the global QueryException(23503) handler, not a 500.
     */
    public function test_deleting_a_group_still_referenced_by_an_assembly_returns_409_not_500(): void
    {
        $user = User::factory()->create();
        $group = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);
        Assembly::create([
            'code' => 'ASM-1', 'name' => 'Assembly One', 'work_uom_id' => $this->each->id,
            'assembly_group_id' => $group->id, 'margin_type' => 'MARGIN', 'margin_pct' => '20',
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/assembly-groups/{$group->id}")->assertStatus(409);
    }

    public function test_a_group_with_no_assemblies_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $group = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);

        $this->actingAs($user)->deleteJson("/api/v1/assembly-groups/{$group->id}")->assertNoContent();

        $this->assertDatabaseMissing('assembly_groups', ['id' => $group->id]);
    }
}

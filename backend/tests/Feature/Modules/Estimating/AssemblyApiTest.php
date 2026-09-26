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

class AssemblyApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $hour;

    private AssemblyGroup $fabrication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hour = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $this->fabrication = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);
    }

    private function otherCompanyId(): int
    {
        return DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
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
        $this->getJson('/api/v1/assemblies')->assertUnauthorized();
    }

    public function test_an_ungrouped_assembly_can_be_created_and_is_draft_by_default(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/assemblies', [
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25.0000',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.code', 'ASM-1')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.assembly_group_id', null);
    }

    public function test_an_assembly_can_be_created_with_a_same_company_group(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/assemblies', [
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'assembly_group_id' => $this->fabrication->id, 'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);

        $response->assertCreated()->assertJsonPath('data.assembly_group_id', $this->fabrication->id);
    }

    public function test_a_cross_company_group_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $otherCompanyId = $this->otherCompanyId();
        $this->actingAsCompany($otherCompanyId);
        $otherGroup = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);
        $this->actingAsCompany(1);

        $this->actingAs($user)->postJson('/api/v1/assemblies', [
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'assembly_group_id' => $otherGroup->id, 'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ])->assertUnprocessable()->assertJsonValidationErrors('assembly_group_id');
    }

    public function test_a_cross_company_work_uom_is_rejected_with_a_422_not_a_500(): void
    {
        $user = User::factory()->create();
        $otherCompanyId = $this->otherCompanyId();
        $this->actingAsCompany($otherCompanyId);
        $otherUom = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $this->actingAsCompany(1);

        $this->actingAs($user)->postJson('/api/v1/assemblies', [
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $otherUom->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_uom_id');
    }

    public function test_an_assemblys_group_can_be_changed_and_removed(): void
    {
        $user = User::factory()->create();
        $other = AssemblyGroup::create(['code' => 'INSTALL', 'name' => 'Installation']);
        $assembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'assembly_group_id' => $this->fabrication->id, 'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);

        $this->actingAs($user)->patchJson("/api/v1/assemblies/{$assembly->id}", [
            'assembly_group_id' => $other->id,
        ])->assertOk()->assertJsonPath('data.assembly_group_id', $other->id);

        $this->patchJson("/api/v1/assemblies/{$assembly->id}", [
            'assembly_group_id' => null,
        ])->assertOk()->assertJsonPath('data.assembly_group_id', null);
    }

    public function test_code_must_be_unique_per_company(): void
    {
        $user = User::factory()->create();
        Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);

        $this->actingAs($user)->postJson('/api/v1/assemblies', [
            'code' => 'ASM-1', 'name' => 'Duplicate', 'work_uom_id' => $this->hour->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '10',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_a_second_companys_assembly_with_the_same_code_is_allowed(): void
    {
        $user = User::factory()->create();
        Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);

        $this->actingAsCompany($this->otherCompanyId());
        $otherUom = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);

        $this->actingAs($user)->postJson('/api/v1/assemblies', [
            'code' => 'ASM-1', 'name' => 'Welded frame too', 'work_uom_id' => $otherUom->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ])->assertCreated();
    }

    public function test_another_companys_assembly_is_not_visible(): void
    {
        $otherCompanyId = $this->otherCompanyId();
        $this->actingAsCompany($otherCompanyId);
        $otherUom = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $otherAssembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $otherUom->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);
        $this->actingAsCompany(1);

        $user = User::factory()->create();

        $this->actingAs($user)->getJson("/api/v1/assemblies/{$otherAssembly->id}")->assertNotFound();
    }

    /**
     * destroy() archives (status = ARCHIVED) rather than deleting — Assembly
     * has no versions/components to lose yet, but the same lifecycle verb as
     * Formulation is kept for consistency ahead of Step 3.
     */
    public function test_deleting_an_assembly_archives_it_instead_of_removing_the_row(): void
    {
        $user = User::factory()->create();
        $assembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);

        $this->actingAs($user)->deleteJson("/api/v1/assemblies/{$assembly->id}")->assertNoContent();

        $this->assertDatabaseHas('assemblies', ['id' => $assembly->id, 'status' => 'ARCHIVED']);
    }

    public function test_an_archived_assembly_can_still_be_fetched_and_listed(): void
    {
        $user = User::factory()->create();
        $assembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);
        $this->actingAs($user)->deleteJson("/api/v1/assemblies/{$assembly->id}")->assertNoContent();

        $this->getJson("/api/v1/assemblies/{$assembly->id}")->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
        $this->getJson('/api/v1/assemblies?filter[status]=ARCHIVED')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_can_include_work_uom_and_assembly_group(): void
    {
        $user = User::factory()->create();
        Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'assembly_group_id' => $this->fabrication->id, 'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);

        $this->actingAs($user)->getJson('/api/v1/assemblies?include=workUom,assemblyGroup')
            ->assertOk()
            ->assertJsonPath('data.0.work_uom.code', 'HR')
            ->assertJsonPath('data.0.assembly_group.code', 'FABRICATION');
    }
}

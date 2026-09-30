<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyGroup;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Support\CompanyContext;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class OverheadPolicyApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $hour;

    private AssemblyGroup $group;

    private Assembly $assembly;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('estimating.overhead-policy.manage');
        Permission::findOrCreate('estimating.policy-history.view');

        $this->hour = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $this->group = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);
        $this->assembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Welded frame', 'work_uom_id' => $this->hour->id,
            'assembly_group_id' => $this->group->id, 'margin_type' => 'MARGIN', 'margin_pct' => '25',
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.overhead-policy.manage', 'estimating.policy-history.view');

        return $user;
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
        $this->getJson('/api/v1/overhead-policies')->assertUnauthorized();
    }

    public function test_index_requires_the_policy_history_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/overhead-policies')->assertForbidden();
    }

    public function test_show_requires_the_policy_history_permission(): void
    {
        $user = User::factory()->create();
        $policy = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'Onboarding default', 'changed_by' => $user->id,
        ]);

        $this->actingAs($user)->getJson("/api/v1/overhead-policies/{$policy->id}")->assertForbidden();
    }

    public function test_store_requires_the_manage_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.policy-history.view');

        $this->actingAs($user)->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'Onboarding default',
        ])->assertForbidden();
    }

    public function test_a_company_scope_policy_can_be_created_and_reason_is_mandatory(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $response = $this->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'Onboarding default',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.scope_type', 'COMPANY')
            ->assertJsonPath('data.scope_id', null)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.changed_by', $user->id);
    }

    public function test_scope_id_is_prohibited_for_company_scope_and_required_otherwise(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'COMPANY', 'scope_id' => $this->assembly->id, 'driver_type' => 'PCT_DIRECT_COST',
            'rate' => '10', 'reason' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_id');

        $this->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'ASSEMBLY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10', 'reason' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_id');
    }

    public function test_a_cross_company_assembly_group_scope_id_is_rejected(): void
    {
        $user = $this->manager();
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompanyId);
        $otherGroup = AssemblyGroup::create(['code' => 'FABRICATION', 'name' => 'Fabrication']);
        $this->actingAsCompany(1);

        $this->actingAs($user)->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'ASSEMBLY_GROUP', 'scope_id' => $otherGroup->id, 'driver_type' => 'PCT_DIRECT_COST',
            'rate' => '10', 'reason' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_id');
    }

    public function test_a_cross_company_assembly_scope_id_is_rejected(): void
    {
        $user = $this->manager();
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompanyId);
        $otherUom = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1']);
        $otherAssembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Other', 'work_uom_id' => $otherUom->id,
            'margin_type' => 'MARGIN', 'margin_pct' => '10',
        ]);
        $this->actingAsCompany(1);

        $this->actingAs($user)->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'ASSEMBLY', 'scope_id' => $otherAssembly->id, 'driver_type' => 'PCT_DIRECT_COST',
            'rate' => '10', 'reason' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('scope_id');
    }

    public function test_rate_over_100_is_rejected_for_a_percentage_driver_but_allowed_for_a_per_unit_driver(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '150', 'reason' => 'x',
        ])->assertUnprocessable()->assertJsonValidationErrors('rate');

        $this->postJson('/api/v1/overhead-policies', [
            'scope_type' => 'COMPANY', 'driver_type' => 'PER_LABOUR_HOUR', 'rate' => '150', 'reason' => 'x',
        ])->assertCreated();
    }

    public function test_only_a_draft_policy_can_be_activated(): void
    {
        $user = $this->manager();
        $policy = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'x', 'changed_by' => $user->id,
        ]);

        $this->actingAs($user)->postJson("/api/v1/overhead-policies/{$policy->id}/activate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $this->postJson("/api/v1/overhead-policies/{$policy->id}/activate")->assertStatus(422);
    }

    public function test_activating_supersedes_the_previous_active_policy_for_the_same_scope(): void
    {
        $user = $this->manager();
        $first = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'first', 'changed_by' => $user->id,
        ]);
        $this->actingAs($user)->postJson("/api/v1/overhead-policies/{$first->id}/activate")->assertOk();

        $second = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '20',
            'reason' => 'second', 'changed_by' => $user->id,
        ]);
        $this->postJson("/api/v1/overhead-policies/{$second->id}/activate")->assertOk();

        $this->assertSame('SUPERSEDED', $first->refresh()->status->value);
        $this->assertSame('ACTIVE', $second->refresh()->status->value);
        $this->assertNotNull($first->effective_to);
        $this->assertNull($second->effective_to);
    }

    public function test_an_active_policy_cannot_be_edited_or_deleted(): void
    {
        $user = $this->manager();
        $policy = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'x', 'changed_by' => $user->id,
        ]);
        $this->actingAs($user)->postJson("/api/v1/overhead-policies/{$policy->id}/activate")->assertOk();

        $this->patchJson("/api/v1/overhead-policies/{$policy->id}", ['rate' => '99'])->assertStatus(422);
        $this->deleteJson("/api/v1/overhead-policies/{$policy->id}")->assertStatus(422);
    }

    public function test_delete_and_activate_require_the_manage_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.policy-history.view');
        $manager = $this->manager();
        $policy = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'x', 'changed_by' => $manager->id,
        ]);

        $this->actingAs($user)->postJson("/api/v1/overhead-policies/{$policy->id}/activate")->assertStatus(422);
        $this->deleteJson("/api/v1/overhead-policies/{$policy->id}")->assertStatus(422);
    }

    public function test_resolution_prefers_assembly_over_group_over_company(): void
    {
        $user = $this->manager();
        $this->activePolicy($user, 'COMPANY', null, 'PER_WORK_UNIT', '5');
        $groupPolicy = $this->activePolicy($user, 'ASSEMBLY_GROUP', $this->group->id, 'PER_WORK_UNIT', '10');

        $response = $this->actingAs($user)->getJson("/api/v1/overhead-policies/resolve?assembly_id={$this->assembly->id}");
        $response->assertOk()
            ->assertJsonPath('data.method', 'ASSEMBLY_GROUP')
            ->assertJsonPath('data.policy.id', $groupPolicy->id);

        $assemblyPolicy = $this->activePolicy($user, 'ASSEMBLY', $this->assembly->id, 'PER_WORK_UNIT', '15');

        $this->getJson("/api/v1/overhead-policies/resolve?assembly_id={$this->assembly->id}")
            ->assertOk()
            ->assertJsonPath('data.method', 'ASSEMBLY')
            ->assertJsonPath('data.policy.id', $assemblyPolicy->id);
    }

    public function test_a_company_with_only_a_company_default_still_resolves_correctly(): void
    {
        $user = $this->manager();
        $companyPolicy = $this->activePolicy($user, 'COMPANY', null, 'PER_WORK_UNIT', '8');

        $response = $this->actingAs($user)->getJson(
            "/api/v1/overhead-policies/resolve?assembly_id={$this->assembly->id}&material_cost=100"
        );

        $response->assertOk()
            ->assertJsonPath('data.method', 'COMPANY')
            ->assertJsonPath('data.policy.id', $companyPolicy->id);
    }

    public function test_resolution_returns_none_when_no_policy_exists_anywhere(): void
    {
        $user = $this->manager();

        $this->actingAs($user)->getJson("/api/v1/overhead-policies/resolve?assembly_id={$this->assembly->id}")
            ->assertOk()
            ->assertJsonPath('data.method', 'NONE')
            ->assertJsonPath('data.policy', null)
            ->assertJsonPath('data.overhead_amount', '0.0000');
    }

    public function test_resolve_requires_no_special_permission(): void
    {
        $user = User::factory()->create();
        $manager = $this->manager();
        $this->activePolicy($manager, 'ASSEMBLY', $this->assembly->id, 'PCT_LABOUR', '20');

        $this->actingAs($user)
            ->getJson("/api/v1/overhead-policies/resolve?assembly_id={$this->assembly->id}&labour_cost=100")
            ->assertOk()
            ->assertJsonPath('data.method', 'ASSEMBLY')
            ->assertJsonMissingPath('data.policy.rate')
            ->assertJsonPath('data.overhead_amount', null);

        $costViewer = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);
        $this->actingAs($costViewer)
            ->getJson("/api/v1/overhead-policies/resolve?assembly_id={$this->assembly->id}&labour_cost=100")
            ->assertOk()
            ->assertJsonPath('data.policy.rate', '20.000000')
            ->assertJsonPath('data.overhead_amount', '20.0000');
    }

    public function test_another_companys_policy_is_not_visible(): void
    {
        $user = $this->manager();
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAsCompany($otherCompanyId);
        $otherCompanyUser = User::factory()->create();
        $otherPolicy = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => 'PCT_DIRECT_COST', 'rate' => '10',
            'reason' => 'x', 'changed_by' => $otherCompanyUser->id,
        ]);
        $this->actingAsCompany(1);

        $this->actingAs($user)->getJson("/api/v1/overhead-policies/{$otherPolicy->id}")->assertNotFound();
    }

    public function test_index_filters_by_scope_and_status(): void
    {
        $user = $this->manager();
        $this->activePolicy($user, 'COMPANY', null, 'PER_WORK_UNIT', '5');
        OverheadPolicy::create([
            'scope_type' => 'ASSEMBLY', 'scope_id' => $this->assembly->id, 'driver_type' => 'PCT_DIRECT_COST',
            'rate' => '10', 'reason' => 'draft candidate', 'changed_by' => $user->id,
        ]);

        $this->actingAs($user)->getJson('/api/v1/overhead-policies?filter[scope_type]=COMPANY')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/overhead-policies?filter[status]=DRAFT')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.scope_type', 'ASSEMBLY');
    }

    private function activePolicy(User $user, string $scopeType, ?int $scopeId, string $driverType, string $rate): OverheadPolicy
    {
        $policy = OverheadPolicy::create([
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'driver_type' => $driverType,
            'rate' => $rate, 'reason' => 'test fixture', 'changed_by' => $user->id,
        ]);

        $this->actingAs($user)->postJson("/api/v1/overhead-policies/{$policy->id}/activate")->assertOk();

        return $policy->refresh();
    }
}

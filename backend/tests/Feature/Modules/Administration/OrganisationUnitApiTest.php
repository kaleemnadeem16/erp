<?php

namespace Tests\Feature\Modules\Administration;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Support\PermissionVocabulary;
use App\Modules\Inventory\Actions\CreateWarehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PermissionedActor;
use Tests\Support\PurchasingActor;
use Tests\TestCase;

/**
 * ADR 0089 (Q26): the company's own organisation tree, its members and the resources each unit owns, and the "only my units" limit on
 * member access. Who may read and change it, the rules that keep it a tree, and that another company's tree is unreachable by id.
 */
class OrganisationUnitApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PermissionedActor::grant(User::factory()->create(), PermissionVocabulary::all());
    }

    private function unit(string $code, ?int $parent = null, array $extra = []): array
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/organisation-units', ['code' => $code, 'name' => strtoupper($code), 'parent_id' => $parent] + $extra)->assertCreated()->json('data');
    }

    private function warehouse(string $code): int
    {
        return app(CreateWarehouse::class)(['code' => $code, 'name' => ucfirst($code).' warehouse', 'location_code' => 'main', 'location_name' => 'Main'], PurchasingActor::make(['inventory.warehouse.manage']))->id;
    }

    public function test_it_requires_authentication_and_the_organisation_capabilities(): void
    {
        $this->getJson('/api/v1/organisation-units')->assertUnauthorized();

        $outsider = PermissionedActor::grant(User::factory()->create(), ['administration.member.view']);
        $this->actingAs($outsider)->getJson('/api/v1/organisation-units')->assertForbidden();
        $this->actingAs($outsider)->postJson('/api/v1/organisation-units', ['code' => 'x', 'name' => 'X'])->assertForbidden();

        $reader = PermissionedActor::grant(User::factory()->create(), ['administration.organisation.view']);
        $unit = $this->unit('hq');
        $this->actingAs($reader)->getJson('/api/v1/organisation-units')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($reader)->getJson("/api/v1/organisation-units/{$unit['id']}")->assertOk();
        $this->actingAs($reader)->postJson('/api/v1/organisation-units', ['code' => 'y', 'name' => 'Y'])->assertForbidden();
        $this->actingAs($reader)->patchJson("/api/v1/organisation-units/{$unit['id']}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($reader)->deleteJson("/api/v1/organisation-units/{$unit['id']}")->assertForbidden();
        $this->actingAs($reader)->putJson("/api/v1/organisation-units/{$unit['id']}/members", ['user_ids' => []])->assertForbidden();
        $this->actingAs($reader)->putJson("/api/v1/organisation-units/{$unit['id']}/resources", ['resources' => []])->assertForbidden();
    }

    public function test_a_company_builds_a_tree_and_it_stays_a_tree(): void
    {
        $hq = $this->unit('hq');
        $site = $this->unit('khi', $hq['id']);
        $team = $this->unit('khi-wh', $site['id']);

        // Codes are the company's own and unique within it.
        $this->actingAs($this->admin)->postJson('/api/v1/organisation-units', ['code' => 'HQ', 'name' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors('code');
        // A parent must exist in this company.
        $this->postJson('/api/v1/organisation-units', ['code' => 'z', 'name' => 'Z', 'parent_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        // A unit cannot be moved under itself or its own descendants.
        $this->patchJson("/api/v1/organisation-units/{$hq['id']}", ['parent_id' => $team['id']])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->patchJson("/api/v1/organisation-units/{$site['id']}", ['parent_id' => $site['id']])->assertUnprocessable();
        // A valid move and rename work.
        $other = $this->unit('lhe', $hq['id']);
        $this->patchJson("/api/v1/organisation-units/{$team['id']}", ['parent_id' => $other['id'], 'name' => 'Lahore team'])->assertOk()->assertJsonPath('data.parent_id', $other['id']);
        // An archived unit receives no new sub-units.
        $this->patchJson("/api/v1/organisation-units/{$other['id']}", ['status' => 'ARCHIVED'])->assertOk();
        $this->postJson('/api/v1/organisation-units', ['code' => 'late', 'name' => 'Late', 'parent_id' => $other['id']])->assertUnprocessable();
    }

    public function test_only_an_empty_unit_can_be_deleted(): void
    {
        $hq = $this->unit('hq');
        $site = $this->unit('khi', $hq['id']);
        $this->actingAs($this->admin)->deleteJson("/api/v1/organisation-units/{$hq['id']}")->assertUnprocessable()->assertJsonValidationErrors('unit');

        $member = User::factory()->create();
        $this->putJson("/api/v1/organisation-units/{$site['id']}/members", ['user_ids' => [$member->id]])->assertOk();
        $this->deleteJson("/api/v1/organisation-units/{$site['id']}")->assertUnprocessable();

        $this->putJson("/api/v1/organisation-units/{$site['id']}/members", ['user_ids' => []])->assertOk();
        $this->deleteJson("/api/v1/organisation-units/{$site['id']}")->assertNoContent();
        $this->deleteJson("/api/v1/organisation-units/{$hq['id']}")->assertNoContent();
    }

    public function test_members_are_set_as_a_whole_from_this_companys_active_people_and_the_change_is_audited(): void
    {
        $unit = $this->unit('khi');
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2']);
        $foreign = DB::table('users')->insertGetId(['company_id' => 2, 'name' => 'Foreign', 'email' => 'foreign@example.com', 'password' => 'x']);
        $suspended = User::factory()->create(['status' => 'SUSPENDED']);

        $this->actingAs($this->admin)->putJson("/api/v1/organisation-units/{$unit['id']}/members", ['user_ids' => [$a->id, $b->id]])->assertOk()->assertJsonCount(2, 'data.members');
        $this->putJson("/api/v1/organisation-units/{$unit['id']}/members", ['user_ids' => [$a->id]])->assertOk()->assertJsonCount(1, 'data.members');
        $this->putJson("/api/v1/organisation-units/{$unit['id']}/members", ['user_ids' => [$foreign]])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/v1/organisation-units/{$unit['id']}/members", ['user_ids' => [$suspended->id]])->assertUnprocessable();

        $this->assertDatabaseHas('activity_log', ['description' => 'unit members changed', 'subject_id' => $unit['id'], 'causer_id' => $this->admin->id]);
    }

    public function test_a_warehouse_belongs_to_at_most_one_unit_and_only_a_real_one_can_be_bound(): void
    {
        $karachi = $this->unit('khi');
        $lahore = $this->unit('lhe');
        $khiWarehouse = $this->warehouse('khi');

        $this->actingAs($this->admin)->putJson("/api/v1/organisation-units/{$karachi['id']}/resources", ['resources' => [['type' => 'warehouse', 'id' => $khiWarehouse]]])
            ->assertOk()->assertJsonPath('data.resources.0.name', 'Khi warehouse')->assertJsonPath('data.resources.0.type_label', 'Warehouse');
        // Already owned by Karachi.
        $this->putJson("/api/v1/organisation-units/{$lahore['id']}/resources", ['resources' => [['type' => 'warehouse', 'id' => $khiWarehouse]]])->assertUnprocessable()->assertJsonValidationErrors('resources');
        // Unknown kind, and a warehouse that does not exist.
        $this->putJson("/api/v1/organisation-units/{$lahore['id']}/resources", ['resources' => [['type' => 'cost-centre', 'id' => 1]]])->assertUnprocessable();
        $this->putJson("/api/v1/organisation-units/{$lahore['id']}/resources", ['resources' => [['type' => 'warehouse', 'id' => 999999]]])->assertUnprocessable();
        // Releasing it from Karachi lets Lahore take it.
        $this->putJson("/api/v1/organisation-units/{$karachi['id']}/resources", ['resources' => []])->assertOk();
        $this->putJson("/api/v1/organisation-units/{$lahore['id']}/resources", ['resources' => [['type' => 'warehouse', 'id' => $khiWarehouse]]])->assertOk();
    }

    public function test_another_companys_tree_is_invisible_and_unreachable_by_id(): void
    {
        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2']);
        $foreignUnit = DB::table('organisation_units')->insertGetId(['company_id' => 2, 'code' => 'secret', 'name' => 'Foreign unit', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $mine = $this->unit('mine');

        $this->actingAs($this->admin)->getJson('/api/v1/organisation-units')->assertOk()->assertJsonMissing(['code' => 'secret']);
        $this->getJson("/api/v1/organisation-units/{$foreignUnit}")->assertNotFound();
        $this->patchJson("/api/v1/organisation-units/{$foreignUnit}", ['name' => 'Hijacked'])->assertNotFound();
        $this->deleteJson("/api/v1/organisation-units/{$foreignUnit}")->assertNotFound();
        $this->putJson("/api/v1/organisation-units/{$foreignUnit}/members", ['user_ids' => []])->assertNotFound();
        $this->putJson("/api/v1/organisation-units/{$foreignUnit}/resources", ['resources' => []])->assertNotFound();
        // A foreign unit cannot be a parent either.
        $this->patchJson("/api/v1/organisation-units/{$mine['id']}", ['parent_id' => $foreignUnit])->assertUnprocessable();
        $this->assertSame('Foreign unit', DB::table('organisation_units')->where('id', $foreignUnit)->value('name'));
    }

    public function test_an_assignment_can_be_limited_to_my_units_and_the_limit_does_not_outlive_its_role(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        // An administrator can only hand out what they hold (the existing escalation guard).
        PermissionedActor::grant($this->admin, ['inventory.stock.view']);
        $member = User::factory()->create();
        $clerk = Role::create(['team_id' => 1, 'name' => 'Warehouse clerk']);
        $clerk->givePermissionTo(PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view'])->getAllPermissions());
        $viewer = Role::create(['team_id' => 1, 'name' => 'Viewer']);
        $viewer->givePermissionTo('inventory.stock.view');

        $this->actingAs($this->admin)->postJson("/api/v1/members/{$member->id}/access", [
            'role_ids' => [$clerk->id, $viewer->id], 'direct_permissions' => [],
            'scope_limits' => [['role_id' => $clerk->id, 'scope' => 'MY_UNITS']],
        ])->assertOk()->assertJsonPath('data.scope_limits.0.role_id', $clerk->id)->assertJsonPath('data.scope_limits.0.scope', 'MY_UNITS');
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'role_id' => $clerk->id, 'scope' => 'MY_UNITS']);

        // Omitting limits keeps them; changing something else does not clear them.
        $this->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$clerk->id, $viewer->id], 'direct_permissions' => []])->assertOk()->assertJsonCount(1, 'data.scope_limits');
        // A limit can only name something the person is being given.
        $this->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$viewer->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $clerk->id, 'scope' => 'MY_UNITS']]])
            ->assertUnprocessable()->assertJsonValidationErrors('scope_limits');
        // Exactly one source per limit; only a known scope.
        $this->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$clerk->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $clerk->id, 'permission' => 'inventory.stock.view', 'scope' => 'MY_UNITS']]])->assertUnprocessable();
        $this->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$clerk->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $clerk->id, 'scope' => 'EVERYTHING']]])->assertUnprocessable();
        // Removing the role removes its limit, so a restriction never outlives what it restricted.
        $this->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [$viewer->id], 'direct_permissions' => []])->assertOk()->assertJsonCount(0, 'data.scope_limits');
        $this->assertSame(0, AccessScopeLimit::query()->where('user_id', $member->id)->count());
    }

    public function test_limiting_access_needs_the_assign_capability_and_a_limit_on_a_direct_permission_works(): void
    {
        $member = User::factory()->create();
        $nobody = PermissionedActor::grant(User::factory()->create(), ['administration.organisation.manage']);
        $this->actingAs($nobody)->postJson("/api/v1/members/{$member->id}/access", ['role_ids' => [], 'direct_permissions' => [], 'scope_limits' => []])->assertForbidden();

        PermissionedActor::grant($this->admin, ['inventory.stock.view']);
        $this->actingAs($this->admin)->postJson("/api/v1/members/{$member->id}/access", [
            'role_ids' => [], 'direct_permissions' => ['inventory.stock.view'], 'scope_limits' => [['permission' => 'inventory.stock.view', 'scope' => 'MY_UNITS']],
        ])->assertOk()->assertJsonPath('data.scope_limits.0.permission', 'inventory.stock.view');
    }

    public function test_the_member_shows_which_units_they_belong_to_to_those_who_administer_access(): void
    {
        $member = User::factory()->create();
        $unit = $this->unit('khi');
        $this->actingAs($this->admin)->putJson("/api/v1/organisation-units/{$unit['id']}/members", ['user_ids' => [$member->id]])->assertOk();

        $this->getJson("/api/v1/members/{$member->id}")->assertOk()->assertJsonPath('data.organisation_units.0.code', 'khi');
        $reader = PermissionedActor::grant(User::factory()->create(), ['administration.member.view']);
        $this->actingAs($reader)->getJson("/api/v1/members/{$member->id}")->assertOk()->assertJsonMissingPath('data.organisation_units')->assertJsonMissingPath('data.scope_limits');
        $this->assertSame(1, OrganisationUnit::query()->count());
    }
}

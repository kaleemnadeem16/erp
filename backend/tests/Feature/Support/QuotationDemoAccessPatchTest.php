<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use App\Support\Demo\QuotationDemoBoundary as Demo;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QuotationDemoAccessPatchTest extends TestCase
{
    use RefreshDatabase;

    private function operator(): User
    {
        $manifest = SalesDutyManifest::shipped()->withActivated(Demo::DUTIES);
        $this->app->instance(SalesDutyManifest::class, $manifest);
        $manifest->registerWith(app(PermissionRegistry::class));

        return PermissionedActor::grant(User::factory()->create(['email' => Demo::OPERATOR_EMAIL]), ['administration.access.assign', ...Demo::DUTIES]);
    }

    /** @return array<string,mixed> */
    private function preparer(): array
    {
        return ['role_ids' => [], 'direct_permissions' => [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE], 'scope_limits' => [
            ['permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN'],
            ['permission' => Duty::QUOTATION_PREPARE, 'scope' => 'OWN'],
        ], 'reason' => 'Approved synthetic preparer preset'];
    }

    public function test_patch_preserves_unrelated_rows_audits_and_reruns_without_another_write(): void
    {
        $actor = $this->operator();
        $member = PermissionedActor::grant(User::factory()->create(['email' => Demo::MAIN_EMAIL]), ['inventory.stock.view']);
        $role = Role::create(['team_id' => 1, 'name' => 'Unrelated retained role']);
        $role->givePermissionTo('inventory.stock.view');
        $member->assignRole($role);
        AccessScopeLimit::create(['user_id' => $member->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS', 'created_by' => $actor->id]);
        AccessScopeLimit::create(['user_id' => $member->id, 'permission' => 'inventory.stock.view', 'scope' => 'MY_UNITS', 'created_by' => $actor->id]);
        $beforeRoles = DB::table('model_has_roles')->where('model_id', $member->id)->get()->toArray();
        $beforeDirect = DB::table('model_has_permissions')->where('model_id', $member->id)->get()->toArray();
        $beforeLimits = DB::table('access_scope_limits')->where('user_id', $member->id)->get()->toArray();
        $sync = app(SyncMemberAccess::class);
        $sync->patchQuotationDemo($member, $this->preparer(), $actor);
        $this->assertEquals($beforeRoles, DB::table('model_has_roles')->where('model_id', $member->id)->get()->toArray());
        foreach ($beforeDirect as $row) {
            $this->assertDatabaseHas('model_has_permissions', (array) $row);
        }
        foreach ($beforeLimits as $row) {
            $this->assertDatabaseHas('access_scope_limits', (array) $row);
        }
        $this->assertTrue($member->fresh()->can('inventory.stock.view'));
        $this->assertTrue($member->fresh()->can(Duty::QUOTATION_PREPARE));
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        $this->assertDatabaseHas('activity_log', ['description' => 'quotation demo access patched', 'causer_id' => $actor->id, 'subject_id' => $member->id]);
        $state = DB::table('access_scope_limits')->where('user_id', $member->id)->orderBy('id')->get()->toArray();
        $epoch = $member->fresh()->authorization_generation;
        $auditCount = DB::table('activity_log')->count();
        $sync->patchQuotationDemo($member, $this->preparer(), $actor);
        $this->assertSame($epoch, $member->fresh()->authorization_generation);
        $this->assertSame($auditCount, DB::table('activity_log')->count());
        $this->assertEquals($state, DB::table('access_scope_limits')->where('user_id', $member->id)->orderBy('id')->get()->toArray());
        $sync->patchQuotationDemo($member, ['role_ids' => [], 'direct_permissions' => [], 'scope_limits' => [], 'reason' => 'Approved off preset'], $actor);
        foreach (Demo::DUTIES as $duty) {
            $this->assertFalse($member->fresh()->can($duty));
        }
        $this->assertTrue($member->fresh()->can('inventory.stock.view'));
        foreach ($beforeLimits as $row) {
            $this->assertDatabaseHas('access_scope_limits', (array) $row);
        }
        $this->assertThrows(fn () => $sync($member, ['role_ids' => [$role->id], 'direct_permissions' => []], $actor), DomainInvariantException::class, 'You cannot grant access that you do not hold.');
        $this->assertFalse($actor->can(Duty::QUOTATION_ISSUE));
        $this->assertFalse($actor->can(Duty::DOCUMENT_REPAIR));
    }

    public function test_environment_identity_tenant_and_self_guards_refuse_without_writes(): void
    {
        $actor = $this->operator();
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $this->app->detectEnvironment(fn () => 'production');
        config(['demo.mode' => false]);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), DomainInvariantException::class, 'unavailable');
        config(['demo.mode' => true]);
        app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor);
        $wrong = User::factory()->create();
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($wrong, $this->preparer(), $actor), DomainInvariantException::class, 'approved synthetic operator');
        $foreign = User::factory()->create();
        // A hydrated foreign target must be refused before reloading its real row.
        $foreign->forceFill(['company_id' => 2]);
        $this->assertThrows(fn () => Demo::assertCompany(2), DomainInvariantException::class, 'unavailable');
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($foreign, $this->preparer(), $actor), ModelNotFoundException::class);
        $company = DB::table('companies')->insertGetId(['name' => 'Foreign synthetic company', 'created_at' => now(), 'updated_at' => now()]);
        $id = DB::table('users')->insertGetId(['company_id' => $company, 'name' => 'Foreign synthetic member', 'email' => 'foreign-demo-patch@fixture.test', 'password' => 'fixture-not-a-login', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $persistedForeign = User::withoutGlobalScopes()->findOrFail($id);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($persistedForeign, $this->preparer(), $actor), ModelNotFoundException::class);
        $this->assertDatabaseMissing('access_scope_limits', ['user_id' => $id]);

        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $member), DomainInvariantException::class, 'You cannot change your own access.');
        $stale = $actor;
        DB::table('users')->where('id', $actor->id)->update(['email' => 'not-the-approved-operator@example.test']);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $stale), DomainInvariantException::class, 'approved synthetic operator');
        $this->assertFalse($wrong->fresh()->can(Duty::QUOTATION_VIEW));
    }

    public function test_current_operator_privilege_and_active_status_are_required_even_for_a_hydrated_actor(): void
    {
        $actor = $this->operator();
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $actor->revokePermissionTo('administration.access.assign');
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), DomainInvariantException::class, 'cannot assign access');
        $actor->givePermissionTo('administration.access.assign');
        DB::table('users')->where('id', $actor->id)->update(['status' => 'SUSPENDED']);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), AuthorizationException::class);
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_VIEW));
        $this->assertDatabaseMissing('activity_log', ['description' => 'quotation demo access patched']);
    }

    public function test_a_limited_operator_cannot_widen_assignment_scope(): void
    {
        $actor = $this->operator();
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        AccessScopeLimit::create(['user_id' => $actor->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN']);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), DomainInvariantException::class, 'independently hold company-wide scope');
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_VIEW));
        $this->assertDatabaseMissing('activity_log', ['description' => 'quotation demo access patched']);
    }

    public function test_roles_created_through_the_real_action_receive_only_the_reviewed_scope(): void
    {
        $actor = $this->operator();
        PermissionedActor::grant($actor, ['administration.role.manage']);
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $role = app(SaveRole::class)(null, ['name' => 'Synthetic quotation manager', 'permissions' => [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE], 'reason' => 'Approved demo role definition'], $actor);
        $patch = ['role_ids' => [(int) $role->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => (int) $role->id, 'scope' => 'MY_UNITS']], 'reason' => 'Reviewed demo manager preset'];
        app(SyncMemberAccess::class)->patchQuotationDemo($member, $patch, $actor);
        $this->assertTrue($member->fresh()->hasRole($role));
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_ASSIGN));
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_ISSUE));
        $epoch = $member->fresh()->authorization_generation;
        app(SyncMemberAccess::class)->patchQuotationDemo($member, $patch, $actor);
        $this->assertSame($epoch, $member->fresh()->authorization_generation);
    }

    public function test_shipped_staged_manifest_refuses_even_handmade_permission_rows(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(['email' => Demo::OPERATOR_EMAIL]), ['administration.access.assign', ...Demo::DUTIES]);
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), DomainInvariantException::class, 'not registered');
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_VIEW));
        $this->assertDatabaseMissing('activity_log', ['description' => 'quotation demo access patched']);
    }

    public function test_a_failure_at_the_audit_step_rolls_back_permissions_scopes_and_epochs(): void
    {
        $actor = $this->operator();
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $epoch = $member->fresh()->authorization_generation;
        DB::unprepared("CREATE FUNCTION quotation_demo_patch_test_refuse_audit() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF NEW.description = 'quotation demo access patched' THEN RAISE EXCEPTION 'Synthetic final audit failure'; END IF; RETURN NEW; END \$\$; CREATE TRIGGER quotation_demo_patch_test_refuse_audit BEFORE INSERT ON activity_log FOR EACH ROW EXECUTE FUNCTION quotation_demo_patch_test_refuse_audit()");
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), QueryException::class, 'Synthetic final audit failure');
        $this->assertSame($epoch, $member->fresh()->authorization_generation);
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_VIEW));
        $this->assertDatabaseMissing('access_scope_limits', ['user_id' => $member->id]);
        $this->assertDatabaseMissing('activity_log', ['description' => 'quotation demo access patched']);
    }

    public function test_grant_ceiling_extra_duties_mixed_roles_and_invalid_scope_are_atomic_refusals(): void
    {
        $actor = $this->operator();
        $member = User::factory()->create(['email' => Demo::MAIN_EMAIL]);
        $actor->revokePermissionTo(Duty::QUOTATION_PREPARE);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), DomainInvariantException::class, 'You cannot grant access that you do not hold.');
        $actor->givePermissionTo(Duty::QUOTATION_PREPARE);
        $extra = $this->preparer();
        $extra['direct_permissions'][] = 'inventory.stock.view';
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $extra, $actor), DomainInvariantException::class, 'another duty');
        $bad = $this->preparer();
        $bad['scope_limits'][1]['scope'] = 'ALL';
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $bad, $actor), DomainInvariantException::class, 'scope mode');
        $this->assertDatabaseMissing('access_scope_limits', ['user_id' => $member->id]);
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_VIEW));
        $this->assertDatabaseMissing('activity_log', ['description' => 'quotation demo access patched']);
        PermissionedActor::grant($actor, ['inventory.stock.view']);
        $role = Role::create(['team_id' => 1, 'name' => 'Refused mixed existing path']);
        $role->givePermissionTo([Duty::QUOTATION_VIEW, 'inventory.stock.view']);
        $member->assignRole($role);
        $this->assertThrows(fn () => app(SyncMemberAccess::class)->patchQuotationDemo($member, $this->preparer(), $actor), DomainInvariantException::class, 'mixed existing role');
        $this->assertTrue($member->fresh()->hasRole($role));
    }
}

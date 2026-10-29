<?php

namespace Tests\Feature\Modules\Jobs;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Jobs\Support\Authority\JobDuty;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Modules\Jobs\Support\Authority\JobDutyStatus;
use App\Modules\Jobs\Support\JobAccess;
use App\Support\PermissionRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * J-AU0 — the non-operative Jobs access foundation over the real database and the shared RecordScope: shipped duties refuse everyone (even hand-made rows),
 * a synthetic ACTIVE manifest proves the predicate mechanics (the holder's own duty AND ALL scope of that duty, each independently), current membership is
 * re-read, and nothing is registered, granted or reachable (JF01–JF06 where they apply here).
 */
class JobAccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $holder;

    private int $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $this->holder = User::factory()->create();
        $this->otherCompany = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, JobDutyStatus> $statuses */
    private function manifest(array $statuses): void
    {
        $this->app->instance(JobDutyManifest::class, new JobDutyManifest(array_map(
            fn (string $name): JobDuty => new JobDuty($name, $statuses[$name], 'op', 'projection', 'gate'), array_keys($statuses)
        )));
    }

    private function grant(User $user, string ...$duties): void
    {
        foreach ($duties as $duty) {
            Permission::findOrCreate($duty, 'web');
        }
        $user->givePermissionTo($duties);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ---- JF01 / JF02: the shipped manifest refuses everyone ---------------------------------------------------------------------------------------------

    public function test_every_shipped_duty_refuses_even_with_a_hand_made_permission_and_an_all_scope(): void
    {
        $names = array_map(fn (JobDuty $duty): string => $duty->name, JobDutyManifest::shipped()->all());
        $this->assertCount(23, $names);
        $this->grant($this->holder, ...$names);

        foreach ($names as $name) {
            $this->assertTrue($this->holder->can($name), 'the row and the grant really exist');
            $this->assertFalse(JobAccess::may($this->holder, $name), $name);
        }
        $this->assertFalse(JobAccess::mayAll($this->holder, ...$names));
        $this->expectException(AuthorizationException::class);
        JobAccess::require($this->holder, 'job.view', 'view a Job');
    }

    public function test_a_synthetic_staged_duty_refuses_everyone_and_so_does_an_unknown_name(): void
    {
        $this->manifest(['job.view' => JobDutyStatus::Staged, 'job.assign' => JobDutyStatus::Specified]);
        $this->grant($this->holder, 'job.view', 'job.assign', 'job.nope');

        foreach (['job.view', 'job.assign', 'job.nope', 'job.*', 'job', ''] as $name) {
            $this->assertFalse(JobAccess::may($this->holder, $name), $name);
        }
    }

    public function test_nothing_is_registered_granted_or_reachable(): void
    {
        $registry = app(PermissionRegistry::class);
        $this->assertSame([], array_values(array_filter($registry->all(), fn ($p) => str_starts_with($p, 'job.'))));
        $this->assertSame(0, Permission::query()->where('name', 'like', 'job.%')->count(), 'no Jobs permission row exists');
        $this->assertSame(0, (int) DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')->where('permissions.name', 'like', 'job.%')->count());
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame([], $uris->filter(fn ($u) => preg_match('#^api/v1/(service-)?jobs#', $u) === 1)->values()->all());
        $this->assertFalse(method_exists($registry, 'registerJobs'));
    }

    // ---- JF03 / JF04 / JF05: the predicate mechanics, on a synthetic ACTIVE manifest ----------------------------------------------------------------------

    public function test_an_active_duty_needs_the_permission_and_all_scope_of_that_duty_and_empty_denies(): void
    {
        $this->manifest(['job.view' => JobDutyStatus::Active, 'job.assign' => JobDutyStatus::Active, 'job.start' => JobDutyStatus::Active]);

        $this->assertFalse(JobAccess::may($this->holder, 'job.view'), 'active but not held');
        $this->assertFalse(JobAccess::may(null, 'job.view'));
        $this->assertFalse(JobAccess::mayAll($this->holder), 'an empty conjunction denies');
        $this->assertFalse(JobAccess::mayAll(null, 'job.view'));

        $this->grant($this->holder, 'job.view');
        $this->assertTrue(JobAccess::may($this->holder, 'job.view'), 'held, company-wide');
        $this->assertFalse(JobAccess::may($this->holder, 'job.assign'), 'another duty is not conferred by this one');
        $this->assertFalse(JobAccess::mayAll($this->holder, 'job.view', 'job.assign'));
        $this->assertTrue(JobAccess::mayAll($this->holder, 'job.view'));
    }

    public function test_a_restricted_duty_is_not_rescued_by_an_unrestricted_one_and_combined_operations_need_both(): void
    {
        $this->manifest(['job.view' => JobDutyStatus::Active, 'job.assign' => JobDutyStatus::Active, 'job.start' => JobDutyStatus::Active]);
        $this->grant($this->holder, 'job.view', 'job.assign', 'job.start');
        AccessScopeLimit::create(['user_id' => $this->holder->id, 'permission' => 'job.start', 'scope' => 'MY_UNITS']);

        $this->assertTrue(JobAccess::may($this->holder, 'job.view'));
        $this->assertTrue(JobAccess::may($this->holder, 'job.assign'));
        $this->assertFalse(JobAccess::may($this->holder, 'job.start'), 'limited scopes are unsupported: refused, not filtered');
        $this->assertFalse(JobAccess::mayAll($this->holder, 'job.assign', 'job.start'), 'assign AND start each need their own ALL scope');
        $this->assertTrue(JobAccess::mayAll($this->holder, 'job.view', 'job.assign'));
    }

    public function test_an_explicit_all_grant_of_the_same_duty_keeps_its_additive_meaning_and_a_limited_grant_alone_fails(): void
    {
        $this->manifest(['job.start' => JobDutyStatus::Active]);
        $limitedRole = Role::create(['name' => 'limited-starters', 'guard_name' => 'web']);
        $openRole = Role::create(['name' => 'open-starters', 'guard_name' => 'web']);
        Permission::findOrCreate('job.start', 'web');
        $limitedRole->givePermissionTo('job.start');
        $openRole->givePermissionTo('job.start');
        $this->holder->assignRole($limitedRole);
        AccessScopeLimit::create(['user_id' => $this->holder->id, 'role_id' => $limitedRole->id, 'scope' => 'MY_UNITS']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(JobAccess::may($this->holder, 'job.start'), 'a limited grant alone fails');
        $this->holder->assignRole($openRole);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue(JobAccess::may($this->holder, 'job.start'), 'an explicit unrestricted grant of the same duty is additive, as everywhere else');
    }

    // ---- JF06: the actor is current ------------------------------------------------------------------------------------------------------------------------

    public function test_a_suspended_foreign_stale_or_revoked_actor_is_refused_without_cached_scope(): void
    {
        $this->manifest(['job.view' => JobDutyStatus::Active]);
        $this->grant($this->holder, 'job.view');
        $this->assertTrue(JobAccess::may($this->holder, 'job.view'));

        // Stale hydrated object: still says Active after the database changed.
        $stale = User::query()->findOrFail($this->holder->id);
        DB::table('users')->where('id', $this->holder->id)->update(['status' => 'SUSPENDED']);
        $this->assertSame(MemberStatus::Active, $stale->status);
        $this->assertFalse(JobAccess::may($stale, 'job.view'), 'suspended in the database');
        DB::table('users')->where('id', $this->holder->id)->update(['status' => 'ACTIVE']);
        $this->assertTrue(JobAccess::may($stale, 'job.view'), 'and active again: nothing was cached');

        // Revocation takes effect at once.
        $this->holder->revokePermissionTo('job.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(JobAccess::may($stale, 'job.view'));

        // A member of another company holding the same name is not a member here.
        $foreign = User::query()->withoutGlobalScopes()->findOrFail(DB::table('users')->insertGetId(['company_id' => $this->otherCompany, 'name' => 'O', 'email' => 'o@other.test', 'password' => 'x',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]));
        $this->assertFalse(JobAccess::may($foreign, 'job.view'));
    }

    public function test_assignment_admin_and_sales_rights_imply_no_jobs_authority(): void
    {
        $this->manifest(['job.assign' => JobDutyStatus::Active, 'job.view' => JobDutyStatus::Active]);
        $admin = Role::create(['name' => 'Administrator', 'guard_name' => 'web']);
        foreach (['sales.opportunity.assign', 'sales.pipeline.view', 'inventory.items.manage'] as $other) {
            Permission::findOrCreate($other, 'web');
            $admin->givePermissionTo($other);
        }
        $this->holder->assignRole($admin);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(JobAccess::may($this->holder, 'job.assign'));
        $this->assertFalse(JobAccess::may($this->holder, 'job.view'));
    }

    public function test_there_is_no_any_writer_may_read_helper(): void
    {
        $this->assertSame(['may', 'mayAll', 'require'], collect((new \ReflectionClass(JobAccess::class))->getMethods(\ReflectionMethod::IS_PUBLIC))->map->getName()->sort()->values()->all());
    }
}

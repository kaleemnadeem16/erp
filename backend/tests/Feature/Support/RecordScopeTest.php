<?php

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use App\Modules\Administration\Models\OrganisationUnitResource;
use App\Support\Scope\RecordScope;
use App\Support\Scope\ResolvedScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ADR 0089 (Q26): effective scope is resolved over additive grants. Unrestricted wins; nothing conferred gives none; a limited grant
 * reaches the person's units and their descendants; a limited person with no unit reaches nothing, never everything.
 */
class RecordScopeTest extends TestCase
{
    use RefreshDatabase;

    private const STOCK = 'inventory.stock.view';

    private OrganisationUnit $hq;

    private OrganisationUnit $karachi;

    private OrganisationUnit $karachiWarehouse;

    private OrganisationUnit $lahore;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach ([self::STOCK, 'inventory.movement.view'] as $permission) {
            Permission::findOrCreate($permission);
        }
        $this->hq = $this->unit('hq', 'Head office');
        $this->karachi = $this->unit('khi', 'Karachi site', $this->hq);
        $this->karachiWarehouse = $this->unit('khi-wh', 'Karachi warehouse team', $this->karachi);
        $this->lahore = $this->unit('lhe', 'Lahore site', $this->hq);
        $this->bind($this->karachiWarehouse, 'warehouse', 101);
        $this->bind($this->lahore, 'warehouse', 102);
    }

    private function unit(string $code, string $name, ?OrganisationUnit $parent = null, string $status = 'ACTIVE'): OrganisationUnit
    {
        return OrganisationUnit::create(['code' => $code, 'name' => $name, 'parent_id' => $parent?->id, 'status' => $status]);
    }

    private function bind(OrganisationUnit $unit, string $type, int $id): void
    {
        OrganisationUnitResource::create(['unit_id' => $unit->id, 'resource_type' => $type, 'resource_id' => $id]);
    }

    private function join(User $user, OrganisationUnit ...$units): void
    {
        foreach ($units as $unit) {
            DB::table('organisation_unit_members')->insert(['company_id' => 1, 'unit_id' => $unit->id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function roleFor(User $user, string $name, string ...$permissions): Role
    {
        $role = Role::create(['team_id' => 1, 'name' => $name]);
        $role->givePermissionTo($permissions);
        $user->assignRole($role);

        return $role;
    }

    private function limitRole(User $user, Role $role): void
    {
        AccessScopeLimit::create(['user_id' => $user->id, 'role_id' => $role->id, 'scope' => 'MY_UNITS']);
    }

    private function scope(User $user, string|array $permissions = self::STOCK): ResolvedScope
    {
        return app(RecordScope::class)->resolve($user->fresh(), $permissions);
    }

    public function test_an_assignment_with_no_limit_is_company_wide_so_nothing_changes_until_one_is_set(): void
    {
        $user = User::factory()->create();
        $this->roleFor($user, 'Warehouse clerk', self::STOCK);

        $scope = $this->scope($user);
        $this->assertTrue($scope->isAll());
        $this->assertNull(app(RecordScope::class)->resourceIds($scope, 'warehouse'), 'null means every warehouse');
    }

    public function test_a_person_who_holds_nothing_that_confers_the_capability_reaches_nothing(): void
    {
        $user = User::factory()->create();
        $this->roleFor($user, 'Other duty', 'inventory.movement.view');

        $scope = $this->scope($user);
        $this->assertTrue($scope->isNone());
        $this->assertSame([], app(RecordScope::class)->resourceIds($scope, 'warehouse'));
        $this->assertTrue(app(RecordScope::class)->resolve(null, self::STOCK)->isNone());
    }

    public function test_a_limited_role_reaches_the_persons_units_and_their_descendants_only(): void
    {
        $user = User::factory()->create();
        $role = $this->roleFor($user, 'Site clerk', self::STOCK);
        $this->limitRole($user, $role);
        $this->join($user, $this->karachi);

        $scope = $this->scope($user);
        $this->assertFalse($scope->isAll());
        $this->assertEqualsCanonicalizing([$this->karachi->id, $this->karachiWarehouse->id], $scope->unitIds(), 'a site includes its warehouse team');
        $this->assertSame([101], app(RecordScope::class)->resourceIds($scope, 'warehouse'), 'Lahore (102) and unbound warehouses are not reached');
    }

    public function test_membership_of_several_units_reaches_all_of_them(): void
    {
        $user = User::factory()->create();
        $this->limitRole($user, $this->roleFor($user, 'Clerk', self::STOCK));
        $this->join($user, $this->karachiWarehouse, $this->lahore);

        $this->assertEqualsCanonicalizing([101, 102], app(RecordScope::class)->resourceIds($this->scope($user), 'warehouse'));
    }

    public function test_a_limited_person_with_no_unit_reaches_nothing_not_everything(): void
    {
        $user = User::factory()->create();
        $this->limitRole($user, $this->roleFor($user, 'Clerk', self::STOCK));

        $scope = $this->scope($user);
        $this->assertFalse($scope->isAll());
        $this->assertFalse($scope->isNone(), 'they hold the capability, but reach no record');
        $this->assertSame([], app(RecordScope::class)->resourceIds($scope, 'warehouse'));
    }

    public function test_grants_are_additive_the_widest_wins(): void
    {
        $user = User::factory()->create();
        $this->limitRole($user, $this->roleFor($user, 'Site clerk', self::STOCK));
        $this->join($user, $this->karachiWarehouse);
        $this->assertSame([101], app(RecordScope::class)->resourceIds($this->scope($user), 'warehouse'));

        // A second, unrestricted source of the same capability makes the person unrestricted: grants never deny.
        $this->roleFor($user, 'Inventory controller', self::STOCK);
        $this->assertTrue($this->scope($user)->isAll());
    }

    public function test_a_limited_direct_permission_is_scoped_and_an_unlimited_one_is_company_wide(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(self::STOCK);
        $this->join($user, $this->lahore);
        $this->assertTrue($this->scope($user)->isAll());

        AccessScopeLimit::create(['user_id' => $user->id, 'permission' => self::STOCK, 'scope' => 'MY_UNITS']);
        $this->assertSame([102], app(RecordScope::class)->resourceIds($this->scope($user), 'warehouse'));
    }

    public function test_asking_for_any_of_several_capabilities_takes_the_widest(): void
    {
        $user = User::factory()->create();
        $this->limitRole($user, $this->roleFor($user, 'Clerk', self::STOCK));
        $this->roleFor($user, 'Ledger reader', 'inventory.movement.view');
        $this->join($user, $this->karachiWarehouse);

        $this->assertSame([101], app(RecordScope::class)->resourceIds($this->scope($user, self::STOCK), 'warehouse'));
        $this->assertTrue($this->scope($user, [self::STOCK, 'inventory.movement.view'])->isAll(), 'the unrestricted source of either capability wins');
    }

    public function test_archived_units_reach_nothing_and_do_not_pass_their_descendants_on(): void
    {
        $user = User::factory()->create();
        $this->limitRole($user, $this->roleFor($user, 'Clerk', self::STOCK));
        $this->join($user, $this->karachi);
        $this->karachiWarehouse->update(['status' => 'ARCHIVED']);
        $this->assertSame([$this->karachi->id], $this->scope($user)->unitIds());

        $this->karachi->update(['status' => 'ARCHIVED']);
        $this->assertSame([], $this->scope($user)->unitIds());
    }

    public function test_another_companys_units_are_never_reached(): void
    {
        DB::table('companies')->insert(['id' => 2, 'name' => 'Second company']);
        DB::table('organisation_units')->insert(['company_id' => 2, 'code' => 'x', 'name' => 'Foreign unit', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organisation_unit_resources')->insert(['company_id' => 2, 'unit_id' => DB::table('organisation_units')->where('company_id', 2)->value('id'), 'resource_type' => 'warehouse', 'resource_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        $this->limitRole($user, $this->roleFor($user, 'Clerk', self::STOCK));
        $this->join($user, $this->karachiWarehouse);

        $this->assertSame([101], app(RecordScope::class)->resourceIds($this->scope($user), 'warehouse'));
    }
}

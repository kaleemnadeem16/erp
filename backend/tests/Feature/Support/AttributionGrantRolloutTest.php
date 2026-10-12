<?php

namespace Tests\Feature\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Support\AccessAssignmentGuard;
use App\Modules\Sales\Support\PermissionVocabulary as Sales;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ADR 0114: the historical-attribution duty is registered like any other capability but is never granted by an automatic "every registered
 * capability" expansion — only explicitly, or to a holder of an existing role under a verified full-access mandate. Explicit grants survive a re-sync.
 */
class AttributionGrantRolloutTest extends TestCase
{
    use RefreshDatabase;

    private const DUTY = Sales::OPPORTUNITY_ATTRIBUTION_CORRECT;

    private function registry(): PermissionRegistry
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        $registry = app(PermissionRegistry::class);
        $registry->registerForTeam(1);

        return $registry;
    }

    private function sync(User $user): void
    {
        $this->artisan('erp:sync-admin-permissions', ['email' => $user->email])->assertSuccessful();
        $user->unsetRelation('permissions')->unsetRelation('roles');
    }

    /** @return array<string, mixed> */
    private function mandate(Role $role, array $over = []): array
    {
        return array_merge(['company_id' => 1, 'role_id' => $role->id, 'mandate' => 'ALL_REGISTERED_CAPABILITIES', 'approval_reference' => 'CHG-1', 'effective_from' => now()->subDay()->toDateString()], $over);
    }

    private function fullAccessRole(): Role
    {
        $role = Role::create(['team_id' => 1, 'name' => 'Company administrator', 'guard_name' => 'web']);
        // A full-access role by today's automatic grants: the duty is not among them, so the mandate is what could add it.
        $role->givePermissionTo($this->registry()->automaticGrants());

        return $role;
    }

    public function test_the_duty_is_registered_but_is_not_part_of_an_automatic_grant(): void
    {
        $registry = $this->registry();
        $this->assertContains(self::DUTY, $registry->all());
        $this->assertContains(self::DUTY, $registry->notAutomaticallyGranted());
        $this->assertNotContains(self::DUTY, $registry->automaticGrants());
        $this->assertSame(count($registry->all()) - 1, count($registry->automaticGrants()));
    }

    public function test_re_syncing_an_administrator_grants_everything_else_but_not_the_duty_and_adds_nothing_twice(): void
    {
        $registry = $this->registry();
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->sync($admin);
        $this->assertFalse($admin->hasPermissionTo(self::DUTY));
        $this->assertSame(count($registry->all()) - 1, $admin->getAllPermissions()->count());
        $this->sync($admin);
        $this->assertSame(count($registry->all()) - 1, $admin->getAllPermissions()->count());
    }

    public function test_an_explicit_grant_survives_a_re_sync(): void
    {
        $this->registry();
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->givePermissionTo(self::DUTY);

        $this->sync($admin);

        $this->assertTrue($admin->hasPermissionTo(self::DUTY));
        $this->assertTrue($admin->getDirectPermissions()->pluck('name')->contains(self::DUTY));
    }

    public function test_a_role_called_admin_or_holding_every_current_permission_is_not_a_mandate(): void
    {
        $registry = $this->registry();
        $role = Role::create(['team_id' => 1, 'name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo($registry->automaticGrants());
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->assignRole($role);

        $this->sync($admin);

        $this->assertFalse($admin->hasPermissionTo(self::DUTY));
    }

    public function test_a_verified_mandate_over_an_existing_role_covers_only_its_holders_in_its_company(): void
    {
        $role = $this->fullAccessRole();
        config(['access.full_access_mandates' => [$this->mandate($role)]]);
        $holder = User::factory()->create(['email' => 'holder@example.com']);
        $holder->assignRole($role);
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);

        $this->sync($holder);
        $this->sync($stranger);

        $this->assertTrue($holder->hasPermissionTo(self::DUTY));
        $this->assertFalse($stranger->hasPermissionTo(self::DUTY), 'the mandate covers the role, not everyone');
    }

    public function test_an_incomplete_foreign_unknown_or_future_mandate_covers_nobody(): void
    {
        $role = $this->fullAccessRole();
        $holder = User::factory()->create(['email' => 'holder@example.com']);
        $holder->assignRole($role);
        $bad = [
            'foreign company' => $this->mandate($role, ['company_id' => 2]),
            'no approval reference' => $this->mandate($role, ['approval_reference' => ' ']),
            'wrong mandate' => $this->mandate($role, ['mandate' => 'SOME_CAPABILITIES']),
            'future effective date' => $this->mandate($role, ['effective_from' => now()->addDay()->toDateString()]),
            'unknown role' => $this->mandate($role, ['role_id' => 99999]),
            'no role id' => $this->mandate($role, ['role_id' => 0]),
        ];
        foreach ($bad as $why => $mandate) {
            config(['access.full_access_mandates' => [$mandate]]);
            $this->sync($holder);
            $this->assertFalse($holder->hasPermissionTo(self::DUTY), $why);
        }
    }

    public function test_create_admin_all_does_not_grant_the_duty(): void
    {
        $this->artisan('erp:create-admin', ['--email' => 'first-admin@example.com', '--name' => 'First Admin', '--permissions' => ['all']])
            ->expectsQuestion('Password (input hidden)', 'A-strong-password-123!')->assertSuccessful();

        $user = User::query()->where('email', 'first-admin@example.com')->firstOrFail();
        $this->assertFalse($user->hasPermissionTo(self::DUTY));
        $this->assertCount(count(app(PermissionRegistry::class)->automaticGrants()), $user->getAllPermissions());
    }

    public function test_access_administration_cannot_grant_the_duty_beyond_its_own_authority(): void
    {
        $registry = $this->registry();
        $guard = app(AccessAssignmentGuard::class);
        $ordinaryAdmin = User::factory()->create();
        $ordinaryAdmin->givePermissionTo($registry->automaticGrants());
        $holder = User::factory()->create();
        $holder->givePermissionTo(self::DUTY);

        try {
            $guard->validate($ordinaryAdmin, [], [self::DUTY]);
            $this->fail('An administrator who does not hold the duty cannot grant it.');
        } catch (DomainInvariantException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame([self::DUTY], $guard->validate($holder, [], [self::DUTY])['permissions']);
    }
}

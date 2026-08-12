<?php

namespace Tests\Feature\Support;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * spatie/laravel-permission, Teams enabled — docs/architecture/authorization.md.
 */
class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Direct model calls (not an HTTP request) never go through
        // App\Http\Middleware\SetPermissionsTeam, so the team has to be set by hand —
        // it's what that middleware does on every real request.
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
    }

    public function test_a_permission_granted_via_a_role_is_inherited(): void
    {
        $permission = Permission::findOrCreate('jobs.approve');
        $role = Role::findOrCreate('estimator');
        $role->givePermissionTo($permission);

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->assertTrue($user->can('jobs.approve'));
    }

    public function test_a_permission_can_be_granted_directly_to_one_user_without_a_role(): void
    {
        Permission::findOrCreate('finance.tax.manage');

        $user = User::factory()->create();
        $user->givePermissionTo('finance.tax.manage');

        $this->assertTrue($user->can('finance.tax.manage'));

        $otherUser = User::factory()->create();
        $this->assertFalse($otherUser->can('finance.tax.manage'));
    }

    public function test_a_real_request_resolves_the_permissions_team_to_the_current_company(): void
    {
        // Reset what setUp() just set, specifically to prove the middleware — not this
        // test's own setup — is what makes the assertion below true.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/user')->assertOk();

        $this->assertSame(
            app(CompanyContext::class)->id(),
            app(PermissionRegistrar::class)->getPermissionsTeamId()
        );
    }
}

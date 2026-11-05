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
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QuotationNamedScopeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_scope_uses_the_real_assignment_and_role_guards_only_with_active_supported_duties(): void
    {
        $manifest = SalesDutyManifest::shipped()->withActivated([Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE]);
        $this->app->instance(SalesDutyManifest::class, $manifest);
        $manifest->registerWith(app(PermissionRegistry::class));
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.access.assign', 'administration.role.manage', Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE, 'inventory.stock.view']);
        $member = User::factory()->create();
        $sync = app(SyncMemberAccess::class);
        $attrs = ['role_ids' => [], 'direct_permissions' => [Duty::QUOTATION_VIEW], 'scope_limits' => [['permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN_OR_DELEGATED']]];
        $sync($member, $attrs, $actor);
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'permission' => Duty::QUOTATION_VIEW, 'scope' => 'OWN_OR_DELEGATED']);
        $this->assertFalse($member->fresh()->can(Duty::QUOTATION_PREPARE));
        $this->assertThrows(fn () => $sync($actor, $attrs, $actor), DomainInvariantException::class, 'You cannot change your own access.');
        $role = Role::create(['team_id' => 1, 'name' => 'Named quotation viewer']);
        $role->givePermissionTo(Duty::QUOTATION_VIEW);
        $sync($member, ['role_ids' => [$role->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $role->id, 'scope' => 'OWN_OR_DELEGATED']]], $actor);
        $this->assertThrows(fn () => app(SaveRole::class)($role, ['name' => $role->name, 'permissions' => [Duty::QUOTATION_VIEW, 'inventory.stock.view'], 'reason' => 'Refused mixed role'], $actor), DomainInvariantException::class);
        $this->assertFalse($role->fresh()->hasPermissionTo('inventory.stock.view'));
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'role_id' => $role->id, 'scope' => AccessScopeLimit::OWN_OR_DELEGATED]);
    }

    public function test_shipped_registration_and_automatic_sync_grant_neither_new_duty_to_any_holder_or_role(): void
    {
        $registry = app(PermissionRegistry::class);
        $this->assertGreaterThan(0, $registry->registerForTeam(1));
        $holder = User::factory()->create();
        $registry->syncAutomatic($holder);
        $role = Role::create(['team_id' => 1, 'name' => 'Automatic quotation exclusion proof']);
        $role->syncPermissions($registry->automaticGrants());
        $this->assertGreaterThan(0, $holder->getDirectPermissions()->count());
        $this->assertGreaterThan(0, $role->permissions()->count());
        foreach ([Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE] as $name) {
            $this->assertNotContains($name, $registry->all());
            $this->assertNotContains($name, $registry->automaticGrants($holder));
            $this->assertDatabaseMissing('permissions', ['name' => $name]);
            $this->assertFalse(DB::table('model_has_permissions as held')->join('permissions as p', 'p.id', '=', 'held.permission_id')->where('p.name', $name)->exists());
            $this->assertFalse(DB::table('role_has_permissions as held')->join('permissions as p', 'p.id', '=', 'held.permission_id')->where('p.name', $name)->exists());
        }
    }
}

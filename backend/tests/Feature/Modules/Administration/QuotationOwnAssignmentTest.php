<?php

namespace Tests\Feature\Modules\Administration;

use App\Exceptions\DomainInvariantException;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QuotationOwnAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_own_supported_and_reserved_or_unrelated_duties_are_rejected(): void
    {
        app(PermissionRegistry::class)->registerVocabulary('fixture-quotation', [PermissionVocabulary::QUOTATION_VIEW]);
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.access.assign', PermissionVocabulary::QUOTATION_VIEW, 'inventory.stock.view']);
        $member = User::factory()->create();
        $sync = app(SyncMemberAccess::class);
        $attrs = ['role_ids' => [], 'direct_permissions' => [PermissionVocabulary::QUOTATION_VIEW], 'scope_limits' => [['permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']]];
        $sync($member, $attrs, $actor);
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']);
        $attrs['scope_limits'][0]['scope'] = 'OWN_OR_DELEGATED';
        $this->assertThrows(fn () => $sync($member, $attrs, $actor), DomainInvariantException::class);
        $attrs = ['role_ids' => [], 'direct_permissions' => ['inventory.stock.view'], 'scope_limits' => [['permission' => 'inventory.stock.view', 'scope' => 'OWN']]];
        $this->assertThrows(fn () => $sync($member, $attrs, $actor), DomainInvariantException::class);
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'permission' => PermissionVocabulary::QUOTATION_VIEW, 'scope' => 'OWN']);
    }

    public function test_mixed_role_cannot_be_assigned_own_or_made_mixed_after_own_assignment(): void
    {
        app(PermissionRegistry::class)->registerVocabulary('fixture-quotation', [PermissionVocabulary::QUOTATION_VIEW]);
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.access.assign', 'administration.role.manage', PermissionVocabulary::QUOTATION_VIEW, 'inventory.stock.view']);
        $member = User::factory()->create();
        $role = Role::create(['team_id' => 1, 'name' => 'quote only']);
        $role->givePermissionTo(PermissionVocabulary::QUOTATION_VIEW);
        $sync = app(SyncMemberAccess::class);
        $attrs = ['role_ids' => [$role->id], 'direct_permissions' => [], 'scope_limits' => [['role_id' => $role->id, 'scope' => 'OWN']]];
        $sync($member, $attrs, $actor);
        $save = app(SaveRole::class);
        $this->assertThrows(fn () => $save($role, ['name' => 'quote only', 'permissions' => [PermissionVocabulary::QUOTATION_VIEW, 'inventory.stock.view'], 'reason' => 'fixture'], $actor), DomainInvariantException::class);
        $this->assertFalse($role->fresh()->hasPermissionTo('inventory.stock.view'));
        $mixed = Role::create(['team_id' => 1, 'name' => 'mixed']);
        $mixed->givePermissionTo([PermissionVocabulary::QUOTATION_VIEW, 'inventory.stock.view']);
        $attrs['role_ids'] = [$mixed->id];
        $attrs['scope_limits'][0]['role_id'] = $mixed->id;
        $this->assertThrows(fn () => $sync($member, $attrs, $actor), DomainInvariantException::class);
        $this->assertDatabaseHas('access_scope_limits', ['user_id' => $member->id, 'role_id' => $role->id, 'scope' => AccessScopeLimit::OWN]);
    }
}

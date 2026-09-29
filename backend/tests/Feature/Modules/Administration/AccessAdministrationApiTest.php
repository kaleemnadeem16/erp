<?php

namespace Tests\Feature\Modules\Administration;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Notifications\MemberInvitation;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class AccessAdministrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_reads_and_writes_are_separately_protected(): void
    {
        $actor = User::factory()->create();
        $viewer = PermissionedActor::grant(User::factory()->create(), ['administration.member.view']);
        $this->actingAs($actor)->getJson('/api/v1/members')->assertForbidden();
        $this->actingAs($viewer)->getJson('/api/v1/members')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/members', ['name' => 'New User', 'email' => 'new@example.com'])->assertForbidden();
    }

    public function test_an_administrator_can_invite_a_member_without_choosing_a_password(): void
    {
        Notification::fake();
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $this->assertSame(MemberStatus::Active, $actor->status);
        $this->assertTrue($actor->can('administration.member.manage'));
        $response = $this->actingAs($actor)->postJson('/api/v1/members', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'role_ids' => [],
            'direct_permissions' => [],
            'reason' => null,
        ]);
        $response->assertCreated()->assertJsonPath('data.status', 'INVITED');
        $member = User::query()->where('email', 'new@example.com')->sole();
        $this->assertSame(MemberStatus::Invited, $member->status);
        Notification::assertSentTo($member, MemberInvitation::class);
    }

    public function test_member_search_and_pagination_reach_later_records(): void
    {
        $viewer = PermissionedActor::grant(User::factory()->create(), ['administration.member.view']);
        User::factory()->create(['name' => 'Alice Example', 'email' => 'alice@example.com']);
        User::factory()->create(['name' => 'Bob Example', 'email' => 'bob@example.com']);

        $this->actingAs($viewer)->getJson('/api/v1/members?search=example.com&per_page=1&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Bob Example')
            ->assertJsonPath('meta.current_page', 2);
    }

    public function test_inviting_with_access_requires_the_access_assignment_permission(): void
    {
        Notification::fake();
        $actor = PermissionedActor::grant(User::factory()->create(), [
            'administration.member.manage',
            'inventory.item.manage',
        ]);

        $this->actingAs($actor)->postJson('/api/v1/members', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'direct_permissions' => ['inventory.item.manage'],
            'reason' => 'Initial responsibility',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_member_details_can_be_updated_without_changing_access(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $member = User::factory()->create(['name' => 'Old Name', 'email' => 'old@example.com']);

        $this->actingAs($actor)->patchJson("/api/v1/members/{$member->id}", [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('users', [
            'id' => $member->id,
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ]);
    }

    public function test_roles_bundle_registered_permissions_and_prevent_privilege_escalation(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.role.manage', 'administration.role.view', 'inventory.item.manage']);
        Permission::findOrCreate('purchasing.purchase-order.issue');
        $this->actingAs($actor)->postJson('/api/v1/roles', ['name' => 'Buyer', 'permissions' => ['purchasing.purchase-order.issue'], 'reason' => 'Create buyer role'])->assertUnprocessable();
        $response = $this->actingAs($actor)->postJson('/api/v1/roles', ['name' => 'Item Manager', 'permissions' => ['inventory.item.manage'], 'reason' => 'Create item role']);
        $response->assertCreated()->assertJsonPath('data.name', 'Item Manager');
    }

    public function test_roles_list_includes_assigned_member_counts_under_api_authentication(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.role.view']);
        $role = Role::query()->create(['team_id' => 1, 'name' => 'Buyer', 'guard_name' => 'web']);
        $member = User::factory()->create();
        $member->assignRole($role);

        $this->actingAs($actor)->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Buyer')
            ->assertJsonPath('data.0.members_count', 1);
    }

    public function test_access_assignment_is_audited_and_cannot_target_self(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.access.assign', 'inventory.item.manage']);
        $member = User::factory()->create();
        $role = Role::query()->create(['team_id' => 1, 'name' => 'Items', 'guard_name' => 'web']);
        $role->givePermissionTo('inventory.item.manage');
        $payload = ['role_ids' => [$role->id], 'direct_permissions' => [], 'reason' => 'New responsibility'];
        $this->actingAs($actor)->postJson("/api/v1/members/{$actor->id}/access", $payload)->assertUnprocessable();
        $this->actingAs($actor)->postJson("/api/v1/members/{$member->id}/access", $payload)->assertOk();
        $this->assertTrue($member->refresh()->can('inventory.item.manage'));
        $this->assertDatabaseHas('activity_log', ['subject_type' => User::class, 'subject_id' => $member->id, 'description' => 'member access changed']);
    }

    public function test_an_administrator_cannot_change_a_role_assigned_to_themself(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), [
            'administration.role.manage',
            'inventory.item.manage',
        ]);
        $role = Role::query()->create(['team_id' => 1, 'name' => 'My Role', 'guard_name' => 'web']);
        $role->givePermissionTo('inventory.item.manage');
        $actor->assignRole($role);

        $this->actingAs($actor)->patchJson("/api/v1/roles/{$role->id}", [
            'name' => 'Changed Role',
            'permissions' => [],
            'reason' => 'Remove access',
        ])->assertUnprocessable();

        $this->assertSame('My Role', $role->refresh()->name);
    }

    public function test_suspension_revokes_login_and_cannot_target_self(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $member = User::factory()->create(['email' => 'worker@example.com', 'password' => 'secret-password']);
        $this->actingAs($actor)->postJson("/api/v1/members/{$actor->id}/suspend", ['reason' => 'No'])->assertUnprocessable();
        $this->actingAs($actor)->postJson("/api/v1/members/{$member->id}/suspend", ['reason' => 'Left company'])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->postJson('/api/v1/login', ['email' => 'worker@example.com', 'password' => 'secret-password'])->assertUnprocessable();
    }

    public function test_invitation_password_setup_activates_only_an_invited_member(): void
    {
        $invited = User::factory()->create([
            'email' => 'invited@example.com',
            'status' => MemberStatus::Invited,
            'email_verified_at' => null,
        ]);
        $suspended = User::factory()->create([
            'email' => 'suspended@example.com',
            'status' => MemberStatus::Suspended,
        ]);

        foreach ([$invited, $suspended] as $member) {
            $this->postJson('/api/v1/reset-password', [
                'email' => $member->email,
                'token' => Password::broker()->createToken($member),
                'password' => 'new-secure-password',
                'password_confirmation' => 'new-secure-password',
            ])->assertOk();
        }

        $this->assertSame(MemberStatus::Active, $invited->refresh()->status);
        $this->assertNotNull($invited->email_verified_at);
        $this->assertSame(MemberStatus::Suspended, $suspended->refresh()->status);
    }
}

<?php

namespace Tests\Feature\Modules\Administration;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Notifications\MemberInvitation;
use App\Modules\Administration\Support\MemberStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class AccessAdministrationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_reads_and_writes_are_separately_protected(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor)->getJson('/api/v1/members')->assertForbidden();
        PermissionedActor::grant($actor, ['administration.member.view']);
        $this->actingAs($actor)->getJson('/api/v1/members')->assertOk();
        $this->actingAs($actor)->postJson('/api/v1/members', ['name' => 'New User', 'email' => 'new@example.com'])->assertForbidden();
    }

    public function test_an_administrator_can_invite_a_member_without_choosing_a_password(): void
    {
        Notification::fake();
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $response = $this->actingAs($actor)->postJson('/api/v1/members', ['name' => 'New User', 'email' => 'new@example.com']);
        $response->assertCreated()->assertJsonPath('data.status', 'INVITED');
        $member = User::query()->where('email', 'new@example.com')->sole();
        $this->assertSame(MemberStatus::Invited, $member->status);
        Notification::assertSentTo($member, MemberInvitation::class);
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

    public function test_suspension_revokes_login_and_cannot_target_self(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $member = User::factory()->create(['email' => 'worker@example.com', 'password' => 'secret-password']);
        $this->actingAs($actor)->postJson("/api/v1/members/{$actor->id}/suspend", ['reason' => 'No'])->assertUnprocessable();
        $this->actingAs($actor)->postJson("/api/v1/members/{$member->id}/suspend", ['reason' => 'Left company'])->assertOk()->assertJsonPath('data.status', 'SUSPENDED');
        $this->postJson('/api/v1/login', ['email' => 'worker@example.com', 'password' => 'secret-password'])->assertUnprocessable();
    }
}

<?php

namespace Tests\Feature\Modules\Administration;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\ChangeMemberStatus;
use App\Modules\Administration\Actions\DeleteRole;
use App\Modules\Administration\Actions\IssueTemporaryMemberPassword;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Actions\SyncMemberAccess;
use App\Modules\Administration\Actions\UpdateMember;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Administration\Support\PermissionVocabulary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class AccessAdministrationActionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_hydrated_foreign_members_and_roles_cannot_be_changed_through_actions(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), PermissionVocabulary::all());
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        $id = DB::table('users')->insertGetId(['company_id' => 2, 'name' => 'Foreign member', 'email' => 'foreign@example.com', 'password' => 'unchanged']);
        $member = User::withoutGlobalScopes()->findOrFail($id);
        $role = Role::create(['team_id' => 2, 'name' => 'Foreign role']);
        $operations = [
            fn () => app(UpdateMember::class)($member, ['name' => 'Changed'], $actor),
            fn () => app(ChangeMemberStatus::class)($member, MemberStatus::Suspended, 'test', $actor),
            fn () => app(SyncMemberAccess::class)($member, ['role_ids' => [], 'direct_permissions' => []], $actor),
            fn () => app(IssueTemporaryMemberPassword::class)($member, $actor),
            fn () => app(SaveRole::class)($role, ['name' => 'Changed', 'permissions' => [], 'reason' => 'test'], $actor),
            fn () => app(DeleteRole::class)($role, 'test', $actor),
        ];
        $activities = DB::table('activity_log')->count();
        foreach ($operations as $operation) {
            try {
                $operation();
                $this->fail('A hydrated foreign target was accepted.');
            } catch (ModelNotFoundException) {
                $this->assertSame('Foreign member', DB::table('users')->where('id', $id)->value('name'));
                $this->assertSame('Foreign role', DB::table('roles')->where('id', $role->id)->value('name'));
                $this->assertSame($activities, DB::table('activity_log')->count());
            }
        }
        $this->assertSame('unchanged', DB::table('users')->where('id', $id)->value('password'));
        $this->assertDatabaseMissing('model_has_permissions', ['model_id' => $id]);
    }

    public function test_profile_action_cannot_replace_credentials_status_or_invitation_metadata(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $member = User::factory()->create();
        $password = $member->password;
        $result = app(UpdateMember::class)($member, ['name' => 'New name', 'password' => 'attacker-password', 'status' => 'SUSPENDED', 'invited_by' => $actor->id], $actor);
        $this->assertSame('New name', $result->name);
        $this->assertSame($password, $result->password);
        $this->assertSame(MemberStatus::Active, $result->status);
        $this->assertNull($result->invited_by);
    }

    public function test_history_returns_only_scoped_administration_projection_and_requires_read_capability(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.member.manage']);
        $viewer = PermissionedActor::grant(User::factory()->create(), ['administration.member.view']);
        $member = User::factory()->create();
        activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => 'Approved duty', 'before' => ['password' => 'secret-hash'], 'token' => 'secret-token'])->log('member access changed');
        activity()->performedOn($member)->causedBy($actor)->withProperties(['reason' => 'unrelated-secret'])->log('unrelated business event');
        DB::table('companies')->insert(['id' => 2, 'name' => 'Foreign company']);
        DB::table('activity_log')->insert(['company_id' => 2, 'log_name' => 'default', 'description' => 'member access changed', 'subject_type' => User::class, 'subject_id' => $member->id, 'properties' => json_encode(['reason' => 'foreign-secret']), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($actor)->getJson("/api/v1/members/{$member->id}/history")->assertForbidden()->assertJsonMissing(['reason' => 'Approved duty']);
        $response = $this->actingAs($viewer)->getJson("/api/v1/members/{$member->id}/history")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Approved duty');
        foreach (['secret-hash', 'secret-token', 'unrelated-secret', 'foreign-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getJson("/api/v1/members/{$member->id}")->assertOk()->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.credential_version')->assertJsonMissingPath('data.tokens');
    }
}

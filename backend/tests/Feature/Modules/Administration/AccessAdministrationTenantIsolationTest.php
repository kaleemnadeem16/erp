<?php

namespace Tests\Feature\Modules\Administration;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Support\PermissionVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * Access administration must never reach another company's members or roles, however fully empowered the administrator is
 * (docs/architecture/access-security-audit.md, Administration). Every member and role route is tried against a record that
 * belongs to company 2, as a company-1 administrator holding every administration capability.
 */
class AccessAdministrationTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $foreignMemberId;

    private int $foreignRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = PermissionedActor::grant(User::factory()->create(), PermissionVocabulary::all());
        DB::table('companies')->insert(['id' => 2, 'name' => 'Company 2', 'created_at' => now(), 'updated_at' => now()]);
        $this->foreignMemberId = DB::table('users')->insertGetId([
            'company_id' => 2, 'name' => 'Foreign member', 'email' => 'foreign@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->foreignRoleId = Role::query()->withoutGlobalScopes()->create(['name' => 'Foreign role', 'guard_name' => 'web', 'team_id' => 2])->id;
    }

    public function test_a_foreign_member_is_invisible_in_lists_and_unreachable_by_id(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/members')->assertOk()
            ->assertJsonMissing(['email' => 'foreign@example.com']);

        $id = $this->foreignMemberId;
        $this->getJson("/api/v1/members/{$id}")->assertNotFound();
        $this->getJson("/api/v1/members/{$id}/history")->assertNotFound();
        $this->patchJson("/api/v1/members/{$id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->postJson("/api/v1/members/{$id}/access", ['role_ids' => [], 'direct_permissions' => [], 'reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/members/{$id}/suspend", ['reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/members/{$id}/reactivate", [])->assertNotFound();
        $this->postJson("/api/v1/members/{$id}/resend-invitation", [])->assertNotFound();
        $this->postJson("/api/v1/members/{$id}/send-password-reset", [])->assertNotFound();
        $this->postJson("/api/v1/members/{$id}/issue-temporary-password", ['reason' => 'x'])->assertNotFound();

        $this->assertSame('Foreign member', DB::table('users')->where('id', $id)->value('name'));
    }

    public function test_a_foreign_role_is_invisible_unchangeable_and_cannot_be_assigned(): void
    {
        $this->actingAs($this->admin)->getJson('/api/v1/roles')->assertOk()->assertJsonMissing(['name' => 'Foreign role']);

        $id = $this->foreignRoleId;
        $this->getJson("/api/v1/roles/{$id}")->assertNotFound();
        $this->patchJson("/api/v1/roles/{$id}", ['name' => 'Renamed', 'permissions' => [], 'reason' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/roles/{$id}", ['reason' => 'x'])->assertNotFound();
        $this->assertSame('Foreign role', DB::table('roles')->where('id', $id)->value('name'));

        // Assigning it to one of our own members is refused too, not silently accepted.
        $own = User::factory()->create();
        $this->postJson("/api/v1/members/{$own->id}/access", ['role_ids' => [$id], 'direct_permissions' => [], 'reason' => 'x'])->assertUnprocessable();
        $this->assertSame(0, DB::table('model_has_roles')->where('role_id', $id)->count());
    }
}

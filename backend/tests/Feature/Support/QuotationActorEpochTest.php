<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\AssertsPopulatedRollbackRefusal;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class QuotationActorEpochTest extends TestCase
{
    use AssertsPopulatedRollbackRefusal, RefreshDatabase;

    private function epoch(User $actor): int
    {
        return (int) DB::table('users')->where('id', $actor->id)->value('authorization_generation');
    }

    /** Raw storage fixture only; the ordinary model correctly refuses foreign-company creation. */
    private function foreignActor(): User
    {
        $id = DB::table('users')->insertGetId(['company_id' => 2, 'name' => 'Foreign epoch fixture', 'email' => 'foreign-epoch@example.test',
            'password' => 'synthetic-not-a-login', 'status' => MemberStatus::Active->value, 'created_at' => now(), 'updated_at' => now()]);

        return User::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_direct_revoke_and_regrant_permanently_advance_only_the_changed_actor(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();
        $this->assertSame(0, $this->epoch($actor));
        PermissionedActor::grant($actor, [Duty::QUOTATION_VIEW]);
        $granted = $this->epoch($actor);
        $this->assertGreaterThan(0, $granted);
        $actor->revokePermissionTo(Duty::QUOTATION_VIEW);
        $revoked = $this->epoch($actor);
        $this->assertGreaterThan($granted, $revoked);
        $actor->givePermissionTo(Duty::QUOTATION_VIEW);
        $this->assertGreaterThan($revoked, $this->epoch($actor));
        $this->assertSame(0, $this->epoch($other));
    }

    public function test_role_and_scope_mutations_advance_the_affected_actor_and_cannot_restore_a_pin(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), [Duty::QUOTATION_VIEW]);
        $role = Role::create(['team_id' => 1, 'name' => 'Quotation role epoch fixture']);
        $role->givePermissionTo(Duty::QUOTATION_VIEW);
        $before = $this->epoch($actor);
        $actor->assignRole($role);
        $assigned = $this->epoch($actor);
        $this->assertGreaterThan($before, $assigned);
        $limit = AccessScopeLimit::create(['user_id' => $actor->id, 'role_id' => $role->id, 'scope' => AccessScopeLimit::OWN]);
        $limited = $this->epoch($actor);
        $this->assertGreaterThan($assigned, $limited);
        $limit->update(['scope' => AccessScopeLimit::MY_UNITS]);
        $changed = $this->epoch($actor);
        $this->assertGreaterThan($limited, $changed);
        $limit->delete();
        $removed = $this->epoch($actor);
        $this->assertGreaterThan($changed, $removed);
        $actor->removeRole($role);
        $this->assertGreaterThan($removed, $this->epoch($actor));
    }

    public function test_suspend_and_reactivate_are_permanent_changes_but_profile_and_login_are_not(): void
    {
        $actor = User::factory()->create();
        $before = $this->epoch($actor);
        DB::table('users')->where('id', $actor->id)->update(['name' => 'Changed profile', 'last_login_at' => now()]);
        $this->assertSame($before, $this->epoch($actor));
        DB::table('users')->where('id', $actor->id)->update(['status' => MemberStatus::Suspended->value]);
        $suspended = $this->epoch($actor);
        $this->assertGreaterThan($before, $suspended);
        DB::table('users')->where('id', $actor->id)->update(['status' => MemberStatus::Active->value]);
        $this->assertGreaterThan($suspended, $this->epoch($actor));
    }

    public function test_an_actor_epoch_cannot_decrease_even_with_a_simultaneous_status_change(): void
    {
        $actor = User::factory()->create();
        DB::table('users')->where('id', $actor->id)->update(['authorization_generation' => 10]);
        foreach ([['authorization_generation' => 9], ['authorization_generation' => 0, 'status' => MemberStatus::Suspended->value]] as $values) {
            try {
                DB::transaction(fn () => DB::table('users')->where('id', $actor->id)->update($values));
                $this->fail('A pinned actor epoch cannot be restored.');
            } catch (QueryException $refusal) {
                $this->assertSame('23514', $refusal->errorInfo[0]);
            }
            $this->assertSame(10, $this->epoch($actor));
            $this->assertSame(MemberStatus::Active->value, DB::table('users')->where('id', $actor->id)->value('status'));
        }
    }

    public function test_assignment_store_truncation_conservatively_invalidates_every_actor(): void
    {
        $first = PermissionedActor::grant(User::factory()->create(), [Duty::QUOTATION_VIEW]);
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other epoch fixture company']);
        $foreign = $this->foreignActor();
        PermissionedActor::grant($foreign, [Duty::QUOTATION_VIEW], 2);
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        foreach (['model_has_roles', 'model_has_permissions', 'access_scope_limits'] as $table) {
            $one = $this->epoch($first);
            $two = $this->epoch($foreign);
            DB::statement('TRUNCATE '.$table);
            $this->assertGreaterThan($one, $this->epoch($first));
            $this->assertGreaterThan($two, $this->epoch($foreign));
        }
    }

    public function test_company_transfer_advances_the_actor_epoch_and_foreign_grants_do_not_mutate_a_local_actor(): void
    {
        $local = User::factory()->create();
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other epoch fixture company']);
        $foreign = $this->foreignActor();
        $before = $this->epoch($foreign);
        PermissionedActor::grant($foreign, [Duty::QUOTATION_VIEW], 2);
        $this->assertGreaterThan($before, $this->epoch($foreign));
        $this->assertSame(0, $this->epoch($local));
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        DB::table('users')->where('id', $local->id)->update(['company_id' => 2]);
        $this->assertSame(1, $this->epoch($local));
    }

    public function test_populated_actor_epochs_refuse_rollback_and_retain_the_guards(): void
    {
        $actor = User::factory()->create();
        DB::table('users')->where('id', $actor->id)->update(['authorization_generation' => 1]);
        $this->assertPopulatedRollbackRefused('2026_11_10_010000_advance_quotation_actor_authorization_epochs.php', ['users']);
        $this->assertSame(1, $this->epoch($actor));
    }
}

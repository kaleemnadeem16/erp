<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/** ADR 0087: nobody loses a read on release, and nobody gains one they did not already have. */
class ApprovalAuditorReadsRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_history_readers_keep_ceilings_and_reasons_and_no_one_else_gains_them(): void
    {
        $vocabulary = app(PermissionRegistry::class)->byOwner()['approvals'];
        $this->assertContains('approvals.authority.view', $vocabulary);
        $this->assertContains('approvals.reason.view', $vocabulary);

        $direct = PermissionedActor::grant(User::factory()->create(), ['approvals.history.view']);
        $viaRole = User::factory()->create();
        $role = Role::create(['team_id' => 1, 'name' => 'Approval auditor']);
        $role->givePermissionTo('approvals.history.view');
        $viaRole->assignRole($role);
        $approver = PermissionedActor::grant(User::factory()->create(), ['purchasing.purchase-order.approve']);
        $manager = PermissionedActor::grant(User::factory()->create(), ['approvals.authority.manage']);
        $nobody = User::factory()->create();
        DB::table('companies')->insert(['id' => 2, 'name' => 'Second company']);
        $foreignRole = Role::create(['team_id' => 2, 'name' => 'Approval auditor']);
        $foreignRole->givePermissionTo('approvals.history.view');

        $migration = require database_path('migrations/2026_10_06_170000_grant_approval_auditor_reads_to_history_viewers.php');
        $migration->up();
        $counts = [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()];
        $migration->up();
        $this->assertSame($counts, [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()], 'idempotent');

        foreach ([$direct, $viaRole] as $reader) {
            $this->assertTrue($reader->fresh()->can('approvals.authority.view'));
            $this->assertTrue($reader->fresh()->can('approvals.reason.view'));
        }
        foreach ([$approver, $manager, $nobody] as $other) {
            $this->assertFalse($other->fresh()->can('approvals.authority.view'));
            $this->assertFalse($other->fresh()->can('approvals.reason.view'));
        }
        $this->assertFalse($direct->fresh()->can('approvals.authority.manage'), 'no change authority is granted');
        $this->assertEqualsCanonicalizing(['approvals.history.view', 'approvals.authority.view', 'approvals.reason.view'], $role->permissions()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['approvals.history.view', 'approvals.authority.view', 'approvals.reason.view'], $foreignRole->permissions()->pluck('name')->all(), 'per company');
    }
}

<?php

namespace Tests\Feature\Modules\Approvals;

use App\Models\Role;
use App\Models\User;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class TechnicalReviewRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_and_backfill_preserve_existing_approvers_without_broadening_other_duties(): void
    {
        $this->assertContains('approvals.document.technical-review', app(PermissionRegistry::class)->byOwner()['approvals']);
        $approver = PermissionedActor::grant(User::factory()->create(), ['purchasing.purchase-order.approve']);
        $reader = PermissionedActor::grant(User::factory()->create(), ['purchasing.purchase-order.view']);
        $technical = PermissionedActor::grant(User::factory()->create(), ['approvals.document.technical-review']);
        $role = Role::create(['team_id' => 1, 'name' => 'Existing approver']);
        $role->givePermissionTo('purchasing.purchase-order.approve');
        DB::table('companies')->insert(['id' => 2, 'name' => 'Second company']);
        $foreignRole = Role::create(['team_id' => 2, 'name' => 'Existing approver']);
        $foreignRole->givePermissionTo('purchasing.purchase-order.approve');
        $context = app(CompanyContext::class);
        app()->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $foreign = User::factory()->create();
        app()->instance(CompanyContext::class, $context);
        $prior = DB::table('permissions')->where('name', 'purchasing.purchase-order.approve')->value('id');
        DB::table('model_has_permissions')->insert(['permission_id' => $prior, 'model_type' => User::class, 'model_id' => $foreign->id, 'team_id' => 2]);
        $migration = require database_path('migrations/2026_10_06_150000_add_document_review_profiles_to_approvals.php');
        $migration->grantTechnicalReview();
        $counts = [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()];
        $migration->grantTechnicalReview();
        $this->assertSame($counts, [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()]);
        $this->assertTrue($approver->fresh()->can('approvals.document.technical-review'));
        $this->assertFalse($reader->fresh()->can('approvals.document.technical-review'));
        $this->assertFalse($technical->can('purchasing.purchase-order.approve'));
        $this->assertFalse($technical->can('purchasing.commercial-cost.view'));
        $this->assertContains('approvals.document.technical-review', $role->permissions()->pluck('name')->all());
        $this->assertContains('approvals.document.technical-review', $foreignRole->permissions()->pluck('name')->all());
        $new = DB::table('permissions')->where('name', 'approvals.document.technical-review')->value('id');
        $this->assertDatabaseHas('model_has_permissions', ['permission_id' => $new, 'model_id' => $foreign->id, 'model_type' => User::class, 'team_id' => 2]);
        $this->assertDatabaseCount('roles', 2);
    }
}

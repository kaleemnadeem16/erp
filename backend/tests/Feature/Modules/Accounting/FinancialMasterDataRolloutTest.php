<?php

namespace Tests\Feature\Modules\Accounting;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class FinancialMasterDataRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_preserves_each_existing_maintenance_duty_without_granting_unrelated_readers(): void
    {
        $customer = PermissionedActor::grant(User::factory()->create(), ['parties.customer-account.manage']);
        $supplier = PermissionedActor::grant(User::factory()->create(), ['parties.supplier-account.manage']);
        $item = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $ordinary = PermissionedActor::grant(User::factory()->create(), ['parties.party.manage', 'inventory.stock.view']);
        $role = Role::create(['team_id' => 1, 'name' => 'Category maintainer']);
        PermissionedActor::grant(User::factory()->create(), ['inventory.category.manage']);
        $role->givePermissionTo('inventory.category.manage');
        DB::table('companies')->insert(['id' => 2, 'name' => 'Second company']);
        $foreign = Role::create(['team_id' => 2, 'name' => 'Supplier maintainer']);
        $foreign->givePermissionTo('parties.supplier-account.manage');
        $migration = require database_path('migrations/2026_10_06_120000_grant_financial_master_data_to_existing_maintainers.php');
        $migration->up();
        $counts = [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()];
        $migration->up();
        $this->assertSame($counts, [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()]);
        foreach ([$customer, $supplier, $item] as $member) {
            $member->unsetRelation('permissions')->unsetRelation('roles');
            $this->assertTrue($member->can('accounting.account-mapping.manage'));
            $this->assertTrue($member->can('accounting.account-mapping.view'));
        }
        $this->assertTrue($customer->can('parties.credit-limit.manage'));
        $this->assertTrue($customer->can('parties.payment-terms.manage'));
        $this->assertTrue($supplier->can('parties.payment-terms.manage'));
        $this->assertFalse($supplier->can('parties.credit-limit.view'));
        $this->assertFalse($item->can('parties.payment-terms.manage'));
        $ordinary->unsetRelation('permissions')->unsetRelation('roles');
        $this->assertFalse($ordinary->can('accounting.account-mapping.view'));
        $this->assertFalse($ordinary->can('parties.credit-limit.view'));
        $this->assertContains('accounting.account-mapping.manage', $role->permissions()->pluck('name')->all());
        $this->assertContains('parties.payment-terms.manage', $foreign->permissions()->pluck('name')->all());
        $this->assertNotContains('parties.credit-limit.view', $foreign->permissions()->pluck('name')->all());
        $this->assertDatabaseCount('roles', 2);
        $migration->down();
        $this->assertSame($counts, [DB::table('role_has_permissions')->count(), DB::table('model_has_permissions')->count()]);
    }
}

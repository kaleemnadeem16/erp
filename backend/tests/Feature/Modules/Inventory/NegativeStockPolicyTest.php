<?php

namespace Tests\Feature\Modules\Inventory;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\UpdateInventorySettings;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NegativeStockPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.negative-stock-policy.manage');
    }

    public function test_policy_defaults_are_conservative_and_typed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/inventory-settings')->assertOk()
            ->assertJsonPath('data.negative_stock_mode', 'BLOCK')
            ->assertJsonPath('data.negative_stock_authorisation_mode', 'PRIOR_APPROVAL')
            ->assertJsonPath('data.negative_stock_review_sla_hours', 24)
            ->assertJsonPath('data.negative_stock_allow_self_review', false)
            ->assertJsonPath('data.negative_stock_sales_despatch_enabled', true)
            ->assertJsonPath('data.negative_stock_material_consumption_enabled', true)
            ->assertJsonPath('data.negative_stock_supplier_return_enabled', false)
            ->assertJsonPath('data.negative_stock_scrap_writeoff_enabled', false);
    }

    public function test_policy_change_requires_permission_and_reason_and_records_old_and_new_values(): void
    {
        $user = User::factory()->create();
        $payload = ['negative_stock_mode' => 'AUTHORISED_OVERRIDE', 'reason' => 'Operations can precede receipt entry'];

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', $payload)->assertForbidden();
        $user->givePermissionTo('inventory.negative-stock-policy.manage');
        $this->patchJson('/api/v1/inventory-settings', ['negative_stock_mode' => 'AUTHORISED_OVERRIDE'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/v1/inventory-settings', $payload)->assertOk()
            ->assertJsonPath('data.negative_stock_mode', 'AUTHORISED_OVERRIDE');

        $change = InventoryPolicyChange::query()->sole();
        $this->assertSame(['negative_stock_mode' => 'BLOCK'], $change->old_values);
        $this->assertSame(['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], $change->new_values);
        $this->assertSame($user->id, $change->changed_by);
        $this->assertSame($payload['reason'], $change->reason);
    }

    public function test_non_policy_inventory_setting_does_not_require_elevated_permission_or_audit_reason(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
        ])->assertOk();

        $this->assertDatabaseCount('inventory_policy_changes', 0);
    }

    public function test_review_sla_is_bounded_in_api_and_database(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.negative-stock-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'negative_stock_review_sla_hours' => 169, 'reason' => 'too long',
        ])->assertUnprocessable()->assertJsonValidationErrors('negative_stock_review_sla_hours');

        $this->expectException(QueryException::class);
        DB::table('companies')->where('id', 1)->update(['negative_stock_review_sla_hours' => 0]);
    }

    public function test_action_rejects_policy_changes_without_auditable_context(): void
    {
        $this->expectException(DomainInvariantException::class);
        app(UpdateInventorySettings::class)(['negative_stock_mode' => 'AUTHORISED_OVERRIDE']);
    }

    public function test_policy_audit_rows_are_database_immutable(): void
    {
        $user = User::factory()->create();
        app(UpdateInventorySettings::class)(
            ['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], $user->id, 'approved policy change',
        );

        $this->expectException(QueryException::class);
        DB::table('inventory_policy_changes')->update(['reason' => 'rewritten']);
    }

    public function test_policy_actor_cannot_cross_company_boundary(): void
    {
        DB::table('companies')->insert(['id' => 2, 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        $otherUserId = DB::table('users')->insertGetId([
            'company_id' => 2, 'name' => 'Other', 'email' => 'other-policy@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(DomainInvariantException::class);
        app(UpdateInventorySettings::class)(
            ['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], $otherUserId, 'invalid actor',
        );
    }
}

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
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class NegativeStockPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.negative-stock-policy.manage');
        Permission::findOrCreate('inventory.location-policy.manage');
        Permission::findOrCreate('inventory.valuation-policy.manage');
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

    public function test_location_mode_requires_its_own_permission_reason_and_audit_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
        ])->assertForbidden();
        $user->givePermissionTo('inventory.location-policy.manage');
        $this->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED', 'reason' => 'Enable controlled bin structure',
        ])->assertOk();

        $change = InventoryPolicyChange::query()->sole();
        $this->assertSame('LOCATION_STRUCTURE', $change->policy);
        $this->assertSame(['inventory_location_mode' => 'SIMPLE'], $change->old_values);
        $this->assertSame(['inventory_location_mode' => 'STRUCTURED'], $change->new_values);
    }

    public function test_default_avco_scope_requires_its_own_permission_reason_and_audit_record(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'default_avco_pool_scope' => 'WAREHOUSE_ITEM',
        ])->assertForbidden();
        $user->givePermissionTo('inventory.valuation-policy.manage');
        $this->patchJson('/api/v1/inventory-settings', [
            'default_avco_pool_scope' => 'WAREHOUSE_ITEM', 'reason' => 'Value stock by warehouse',
        ])->assertOk();

        $change = InventoryPolicyChange::query()->sole();
        $this->assertSame('DEFAULT_AVCO_POOL_SCOPE', $change->policy);
        $this->assertSame(['default_avco_pool_scope' => 'COMPANY_ITEM'], $change->old_values);
        $this->assertSame(['default_avco_pool_scope' => 'WAREHOUSE_ITEM'], $change->new_values);
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

    public function test_action_rejects_policy_changes_without_a_reason_or_without_the_capability(): void
    {
        $holder = PermissionedActor::grant(User::factory()->create(), ['inventory.negative-stock-policy.manage']);
        try {
            app(UpdateInventorySettings::class)(['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], $holder);
            $this->fail('A policy change needs a reason.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('reason', $e->field ?? 'reason');
        }

        $this->expectException(DomainInvariantException::class);
        app(UpdateInventorySettings::class)(['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], User::factory()->create(), 'no capability');
    }

    public function test_policy_audit_rows_are_database_immutable(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['inventory.negative-stock-policy.manage']);
        app(UpdateInventorySettings::class)(
            ['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], $user, 'approved policy change',
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
            ['negative_stock_mode' => 'AUTHORISED_OVERRIDE'], User::query()->withoutGlobalScopes()->findOrFail($otherUserId), 'invalid actor',
        );
    }
}

<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RecalculationSettingsPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.recalculation-policy.manage');
        Permission::findOrCreate('inventory.policy-history.view');
    }

    public function test_the_high_risk_threshold_defaults_to_unset(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/inventory-settings')->assertOk()
            ->assertJsonMissingPath('data.recalculation_high_risk_threshold_base_amount');
        $user->givePermissionTo('inventory.recalculation-policy.manage');
        $this->getJson('/api/v1/inventory-settings')->assertOk()
            ->assertJsonPath('data.recalculation_high_risk_threshold_base_amount', null);
    }

    public function test_setting_the_threshold_requires_permission_and_reason_and_records_the_audit_trail(): void
    {
        $user = User::factory()->create();
        $payload = ['recalculation_high_risk_threshold_base_amount' => '5000.0000', 'reason' => 'Board-set materiality ceiling'];

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', $payload)->assertForbidden();
        $user->givePermissionTo('inventory.recalculation-policy.manage');
        $this->patchJson('/api/v1/inventory-settings', ['recalculation_high_risk_threshold_base_amount' => '5000.0000'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/v1/inventory-settings', $payload)->assertOk()
            ->assertJsonPath('data.recalculation_high_risk_threshold_base_amount', '5000.0000');

        $change = InventoryPolicyChange::query()->sole();
        $this->assertSame('RECALCULATION', $change->policy);
        $this->assertSame(['recalculation_high_risk_threshold_base_amount' => null], $change->old_values);
        $this->assertSame(['recalculation_high_risk_threshold_base_amount' => '5000.0000'], $change->new_values);
        $this->assertSame($user->id, $change->changed_by);
    }

    public function test_a_non_positive_threshold_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.recalculation-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'recalculation_high_risk_threshold_base_amount' => '0', 'reason' => 'bad input',
        ])->assertUnprocessable()->assertJsonValidationErrors('recalculation_high_risk_threshold_base_amount');
    }

    public function test_only_policy_maintainers_and_history_auditors_can_read_the_current_threshold(): void
    {
        $maintainer = User::factory()->create();
        $maintainer->givePermissionTo('inventory.recalculation-policy.manage');
        $this->actingAs($maintainer)->patchJson('/api/v1/inventory-settings', [
            'recalculation_high_risk_threshold_base_amount' => '5000.0000',
            'reason' => 'Set high-risk review threshold',
        ])->assertOk();

        $ordinary = User::factory()->create();
        $this->actingAs($ordinary)->getJson('/api/v1/inventory-settings')
            ->assertOk()->assertJsonPath('data.inventory_location_mode', 'SIMPLE')
            ->assertJsonMissingPath('data.recalculation_high_risk_threshold_base_amount');

        $auditor = User::factory()->create();
        $auditor->givePermissionTo('inventory.policy-history.view');
        $this->actingAs($auditor)->getJson('/api/v1/inventory-settings')
            ->assertOk()->assertJsonPath('data.recalculation_high_risk_threshold_base_amount', '5000.0000');
        $this->getJson('/api/v1/inventory-policy-changes?filter[policy]=RECALCULATION')
            ->assertOk()->assertJsonPath('data.0.new_values.recalculation_high_risk_threshold_base_amount', '5000.0000');

        $this->actingAs($maintainer)->getJson('/api/v1/inventory-settings')
            ->assertOk()->assertJsonPath('data.recalculation_high_risk_threshold_base_amount', '5000.0000');
        $this->getJson('/api/v1/inventory-policy-changes?filter[policy]=RECALCULATION')->assertForbidden();
    }

    public function test_the_threshold_can_be_cleared_back_to_null(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.recalculation-policy.manage');
        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'recalculation_high_risk_threshold_base_amount' => '5000.0000', 'reason' => 'set it',
        ])->assertOk();

        $this->patchJson('/api/v1/inventory-settings', [
            'recalculation_high_risk_threshold_base_amount' => null, 'reason' => 'no longer needed',
        ])->assertOk()->assertJsonPath('data.recalculation_high_risk_threshold_base_amount', null);
    }
}

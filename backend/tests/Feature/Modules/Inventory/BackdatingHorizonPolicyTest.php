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

class BackdatingHorizonPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.backdating-horizon-policy.manage');
    }

    public function test_policy_defaults_are_conservative_and_typed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/inventory-settings')->assertOk()
            ->assertJsonMissingPath('data.accounting_timezone')
            ->assertJsonPath('data.backdating_horizon_mode', 'CURRENT_AND_PREVIOUS_PERIOD')
            ->assertJsonPath('data.backdating_posting_floor_subtype', null)
            ->assertJsonPath('data.backdating_posting_floor_fixed_date', null)
            ->assertJsonPath('data.backdating_posting_floor_rolling_days', null);
    }

    public function test_policy_change_requires_permission_and_reason_and_records_old_and_new_values(): void
    {
        $user = User::factory()->create();
        $payload = ['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY', 'reason' => 'Period locks are enough for us'];

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', $payload)->assertForbidden();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');
        $this->patchJson('/api/v1/inventory-settings', ['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY'])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->patchJson('/api/v1/inventory-settings', $payload)->assertOk()
            ->assertJsonPath('data.backdating_horizon_mode', 'PERIOD_LOCK_ONLY');

        $change = InventoryPolicyChange::query()->sole();
        $this->assertSame('BACKDATING_HORIZON', $change->policy);
        $this->assertSame(['backdating_horizon_mode' => 'CURRENT_AND_PREVIOUS_PERIOD'], $change->old_values);
        $this->assertSame(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY'], $change->new_values);
        $this->assertSame($user->id, $change->changed_by);
        $this->assertSame($payload['reason'], $change->reason);
    }

    public function test_changing_both_policy_groups_in_one_request_writes_one_audit_row_each(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');
        Permission::findOrCreate('inventory.negative-stock-policy.manage');
        $user->givePermissionTo('inventory.negative-stock-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'negative_stock_mode' => 'AUTHORISED_OVERRIDE',
            'backdating_horizon_mode' => 'PERIOD_LOCK_ONLY',
            'reason' => 'Coordinated policy update',
        ])->assertOk();

        $this->assertSame(
            ['BACKDATING_HORIZON', 'NEGATIVE_STOCK'],
            InventoryPolicyChange::query()->orderBy('policy')->pluck('policy')->all(),
        );
    }

    public function test_posting_floor_mode_requires_a_subtype_in_the_same_request(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'backdating_horizon_mode' => 'POSTING_FLOOR', 'reason' => 'Switch to a fixed floor',
        ])->assertUnprocessable()->assertJsonValidationErrors('backdating_posting_floor_subtype');
    }

    public function test_fixed_date_subtype_requires_the_fixed_date_and_forbids_rolling_days(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'reason' => 'Switch to a fixed floor',
        ])->assertUnprocessable()->assertJsonValidationErrors('backdating_posting_floor_fixed_date');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
            'backdating_posting_floor_rolling_days' => 30,
            'reason' => 'Switch to a fixed floor',
        ])->assertUnprocessable()->assertJsonValidationErrors('backdating_posting_floor_rolling_days');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
            'reason' => 'Switch to a fixed floor',
        ])->assertOk()
            ->assertJsonPath('data.backdating_posting_floor_subtype', 'FIXED_DATE')
            ->assertJsonPath('data.backdating_posting_floor_fixed_date', '2026-01-01');
    }

    public function test_rolling_formula_subtype_requires_rolling_days_within_bounds(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'ROLLING_FORMULA',
            'backdating_posting_floor_rolling_days' => 3651,
            'reason' => 'Switch to a rolling floor',
        ])->assertUnprocessable()->assertJsonValidationErrors('backdating_posting_floor_rolling_days');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'ROLLING_FORMULA',
            'backdating_posting_floor_rolling_days' => 30,
            'reason' => 'Switch to a rolling floor',
        ])->assertOk()->assertJsonPath('data.backdating_posting_floor_rolling_days', 30);
    }

    public function test_reverting_to_a_non_posting_floor_mode_clears_floor_fields_at_the_database_level(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');
        app(UpdateInventorySettings::class)([
            'backdating_horizon_mode' => 'POSTING_FLOOR',
            'backdating_posting_floor_subtype' => 'FIXED_DATE',
            'backdating_posting_floor_fixed_date' => '2026-01-01',
        ], $user, 'set a fixed floor');

        $this->expectException(QueryException::class);
        DB::table('companies')->where('id', 1)->update(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY']);
    }

    public function test_action_rejects_policy_changes_without_a_reason_or_without_the_capability(): void
    {
        $holder = User::factory()->create();
        $holder->givePermissionTo('inventory.backdating-horizon-policy.manage');
        try {
            app(UpdateInventorySettings::class)(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY'], $holder);
            $this->fail('A policy change needs a reason.');
        } catch (DomainInvariantException $e) {
            $this->assertSame('reason', $e->field ?? 'reason');
        }

        $this->expectException(DomainInvariantException::class);
        $this->expectExceptionMessage('not permitted');
        app(UpdateInventorySettings::class)(['backdating_horizon_mode' => 'PERIOD_LOCK_ONLY'], User::factory()->create(), 'no capability');
    }

    public function test_the_time_zone_is_no_longer_an_inventory_setting(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.backdating-horizon-policy.manage');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', ['accounting_timezone' => 'Asia/Karachi', 'reason' => 'ignored'])->assertOk();

        $this->assertSame('UTC', DB::table('companies')->where('id', 1)->value('timezone'));
        $this->assertSame(0, InventoryPolicyChange::query()->count());
    }
}

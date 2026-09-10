<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Read-only audit trail for InventorySettings policy changes (ADR 0047) —
 * every row is written by App\Modules\Inventory\Actions\UpdateInventorySettings;
 * there is nothing to write here directly. Reading it needs its own
 * `inventory.policy-history.view` permission, deliberately separate from the
 * per-policy-group manage permissions — who changed a policy, why and when
 * is more sensitive than the current settings, which any user who needs
 * them can already see.
 */
class InventoryPolicyChangeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.location-policy.manage');
        Permission::findOrCreate('inventory.policy-history.view');
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/inventory-policy-changes')->assertUnauthorized();
    }

    public function test_index_requires_the_policy_history_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/inventory-policy-changes')->assertForbidden();
    }

    public function test_the_settings_manage_permission_alone_does_not_grant_history_access(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.location-policy.manage');
        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
            'reason' => 'Enable structured location operations',
        ])->assertOk();

        // Same user, same company, real policy-change row now exists — but no
        // inventory.policy-history.view permission, so the audit trail stays hidden.
        $this->getJson('/api/v1/inventory-policy-changes')->assertForbidden();
    }

    public function test_index_reflects_a_real_policy_change(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.location-policy.manage', 'inventory.policy-history.view');

        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
            'reason' => 'Enable structured location operations',
        ])->assertOk();

        $response = $this->getJson('/api/v1/inventory-policy-changes');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.policy', 'LOCATION_STRUCTURE');
        $response->assertJsonPath('data.0.reason', 'Enable structured location operations');
        $response->assertJsonPath('data.0.new_values.inventory_location_mode', 'STRUCTURED');
    }

    public function test_index_filters_by_policy(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.location-policy.manage', 'inventory.policy-history.view');
        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
            'reason' => 'Enable structured location operations',
        ])->assertOk();

        $this->getJson('/api/v1/inventory-policy-changes?filter[policy]=LOCATION_STRUCTURE')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/inventory-policy-changes?filter[policy]=DEFAULT_AVCO_POOL_SCOPE')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_index_rejects_invalid_sort(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.policy-history.view');

        $this->actingAs($user)
            ->getJson('/api/v1/inventory-policy-changes?sort=policy')
            ->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_another_companys_policy_change_is_not_visible(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('inventory.location-policy.manage', 'inventory.policy-history.view');
        $this->actingAs($user)->patchJson('/api/v1/inventory-settings', [
            'inventory_location_mode' => 'STRUCTURED',
            'reason' => 'Enable structured location operations',
        ])->assertOk();

        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Other Co', 'created_at' => now(), 'updated_at' => now()]);

        // SetPermissionsTeam middleware derives the live permission team from
        // CompanyContext on every request, so this grant must be made under the
        // other company's team id — granting it while team 1 is still active (as
        // set in setUp) would store a permission the other company's own
        // requests, scoped to team $otherCompanyId, can never see.
        app(PermissionRegistrar::class)->setPermissionsTeamId($otherCompanyId);
        $otherCompanyUser = User::factory()->create();
        $otherCompanyUser->givePermissionTo('inventory.policy-history.view');

        $this->app->instance(CompanyContext::class, new class($otherCompanyId) extends CompanyContext
        {
            public function __construct(private readonly int $companyId) {}

            public function id(): int
            {
                return $this->companyId;
            }
        });

        $this->actingAs($otherCompanyUser)->getJson('/api/v1/inventory-policy-changes')
            ->assertOk()->assertJsonCount(0, 'data');
    }
}

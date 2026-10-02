<?php

namespace Tests\Feature\Modules\Inventory;

use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Modules\Inventory\Support\LocationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * What counts as usable stock is planner-visible policy, so marking a location is governed like the other
 * inventory policies: its own permission on top of ordinary location maintenance, a reason, STRUCTURED mode
 * only, and a history entry. Editing a location at all now needs `inventory.location.manage`.
 */
class LocationUsabilityOverrideTest extends TestCase
{
    use RefreshDatabase;

    private Location $returns;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('inventory.location.manage');
        Permission::findOrCreate('inventory.location-policy.manage');
        Company::query()->findOrFail(1)->update(['inventory_location_mode' => InventoryLocationMode::Structured]);
        $warehouse = Warehouse::create(['code' => 'main', 'name' => 'Main']);
        $warehouse->locations()->create(['code' => 'main', 'name' => 'Main']);
        $this->returns = $warehouse->locations()->create(['code' => 'ret', 'name' => 'Returns', 'type' => LocationType::Returns]);
    }

    private function user(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $this->actingAs($user)->app['auth']->user();
    }

    public function test_editing_creating_or_archiving_a_location_needs_the_location_permission(): void
    {
        $nobody = $this->user();
        $this->patchJson("/api/v1/locations/{$this->returns->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->postJson('/api/v1/locations', ['warehouse_id' => $this->returns->warehouse_id, 'code' => 'new', 'name' => 'New'])->assertForbidden();
        $this->deleteJson("/api/v1/locations/{$this->returns->id}")->assertForbidden();
        $this->assertSame('Returns', $this->returns->fresh()->name);

        $this->user('inventory.location.manage');
        $this->patchJson("/api/v1/locations/{$this->returns->id}", ['name' => 'Renamed'])->assertOk();
        $this->assertNotNull($nobody);
    }

    public function test_marking_a_location_also_needs_the_policy_permission(): void
    {
        $this->user('inventory.location.manage');
        $this->patchJson("/api/v1/locations/{$this->returns->id}", ['counts_as_usable' => true, 'counts_as_usable_reason' => 'Good stock returns here'])->assertForbidden();
        $this->assertNull($this->returns->fresh()->counts_as_usable);
    }

    public function test_a_mark_needs_a_reason_and_is_recorded_in_policy_history(): void
    {
        $user = $this->user('inventory.location.manage', 'inventory.location-policy.manage');

        $this->patchJson("/api/v1/locations/{$this->returns->id}", ['counts_as_usable' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('counts_as_usable_reason');
        $this->assertNull($this->returns->fresh()->counts_as_usable);
        $this->assertSame(0, InventoryPolicyChange::query()->count());

        $this->patchJson("/api/v1/locations/{$this->returns->id}", ['counts_as_usable' => true, 'counts_as_usable_reason' => 'Good stock returns here'])
            ->assertOk()
            ->assertJsonPath('data.counts_as_usable', true)
            ->assertJsonPath('data.counts_as_usable_effective', true);

        $change = InventoryPolicyChange::query()->sole();
        $this->assertSame('location_usability', $change->policy);
        $this->assertSame(['location_id' => $this->returns->id, 'counts_as_usable' => null], $change->old_values);
        $this->assertSame(['location_id' => $this->returns->id, 'counts_as_usable' => true], $change->new_values);
        $this->assertSame('Good stock returns here', $change->reason);
        $this->assertSame($user->id, $change->changed_by);
    }

    public function test_resubmitting_the_same_mark_writes_no_history_and_clearing_follows_the_type_again(): void
    {
        $this->user('inventory.location.manage', 'inventory.location-policy.manage');
        $url = "/api/v1/locations/{$this->returns->id}";
        $this->patchJson($url, ['counts_as_usable' => true, 'counts_as_usable_reason' => 'Good stock returns here'])->assertOk();
        $this->patchJson($url, ['counts_as_usable' => true, 'counts_as_usable_reason' => 'Same again'])->assertOk();
        $this->assertSame(1, InventoryPolicyChange::query()->count());

        // Clearing is a change too, so it needs its reason even though the value is null.
        $this->patchJson($url, ['counts_as_usable' => null])->assertUnprocessable();
        $this->patchJson($url, ['counts_as_usable' => null, 'counts_as_usable_reason' => 'Back to the default'])
            ->assertOk()->assertJsonPath('data.counts_as_usable', null)->assertJsonPath('data.counts_as_usable_effective', false);
        $this->assertSame(2, InventoryPolicyChange::query()->count());
    }

    public function test_a_simple_mode_company_cannot_mark_a_location(): void
    {
        Company::query()->findOrFail(1)->update(['inventory_location_mode' => InventoryLocationMode::Simple]);
        $this->user('inventory.location.manage', 'inventory.location-policy.manage');

        $this->patchJson("/api/v1/locations/{$this->returns->id}", ['counts_as_usable' => true, 'counts_as_usable_reason' => 'x'])->assertUnprocessable();
        $this->assertNull($this->returns->fresh()->counts_as_usable);
    }

    public function test_structured_mode_cannot_be_switched_off_while_a_mark_exists(): void
    {
        Permission::findOrCreate('inventory.policy-history.view');
        $this->returns->update(['type' => LocationType::Storage]);
        // Only the mark is "structured" here, so the downgrade is refused because of it alone.
        $this->user('inventory.location-policy.manage');
        $this->returns->warehouse->locations()->where('id', '!=', $this->returns->id)->delete();
        $this->returns->update(['counts_as_usable' => false]);

        $this->patchJson('/api/v1/inventory-settings', ['inventory_location_mode' => 'SIMPLE', 'reason' => 'Try downgrade'])->assertUnprocessable();
    }
}

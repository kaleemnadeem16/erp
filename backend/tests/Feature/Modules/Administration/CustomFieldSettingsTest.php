<?php

namespace Tests\Feature\Modules\Administration;

use App\Exceptions\DomainInvariantException;
use App\Models\CustomFieldDefinition;
use App\Models\Role;
use App\Models\User;
use App\Modules\Administration\Actions\CreateCustomField;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class CustomFieldSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollout_grants_only_full_administration_bundles_and_is_idempotent_per_company(): void
    {
        $full = PermissionedActor::grant(User::factory()->create(), ['administration.role.manage', 'administration.access.assign']);
        $partial = PermissionedActor::grant(User::factory()->create(), ['administration.role.manage']);
        $role = Role::create(['name' => 'Company administrator', 'guard_name' => 'web', 'team_id' => 2]);
        $role->givePermissionTo(['administration.role.manage', 'administration.access.assign']);
        $migration = require base_path('database/migrations/2026_10_06_180000_grant_custom_field_setup_to_access_administrators.php');
        $migration->up();
        $migration->up();
        $this->assertTrue($full->fresh()->can('administration.custom-field.manage'));
        $this->assertFalse($partial->fresh()->can('administration.custom-field.manage'));
        $this->assertTrue($role->fresh()->hasPermissionTo('administration.custom-field.manage'));
        $this->assertSame(1, DB::table('model_has_permissions')->where('model_id', $full->id)->where('permission_id', Permission::findByName('administration.custom-field.manage')->id)->count());
    }

    private function payload(): array
    {
        return ['entity_type' => 'items', 'code' => 'manufacturer', 'label' => 'Manufacturer', 'data_type' => 'string'];
    }

    public function test_settings_require_a_capability_and_item_maintainers_can_read_only_item_definitions(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/custom-field-definitions?entity_type=items')->assertForbidden();
        $this->postJson('/api/v1/custom-field-definitions', $this->payload())->assertForbidden();
        $this->expectException(DomainInvariantException::class);
        app(CreateCustomField::class)($this->payload(), $user);
    }

    public function test_required_definitions_cannot_be_bypassed_by_omitting_values_on_creation(): void
    {
        $actor = PermissionedActor::grant(User::factory()->create(), ['administration.custom-field.manage', 'parties.party.manage']);
        $this->actingAs($actor)->postJson('/api/v1/custom-field-definitions', array_replace($this->payload(), ['entity_type' => 'parties', 'is_required' => true]))->assertCreated();
        $this->postJson('/api/v1/parties', ['code' => 'new-partner', 'name' => 'Partner'])->assertUnprocessable()->assertJsonValidationErrors('custom_fields');
        $this->postJson('/api/v1/parties', ['code' => 'new-partner', 'name' => 'Partner', 'custom_fields' => ['manufacturer' => 'Factory']])->assertCreated()->assertJsonPath('data.custom_fields.manufacturer', 'Factory');
    }

    public function test_admin_can_define_fields_and_choices_and_validation_is_entity_and_company_scoped(): void
    {
        $admin = PermissionedActor::grant(User::factory()->create(), ['administration.custom-field.manage']);
        $this->actingAs($admin)->postJson('/api/v1/custom-field-definitions', $this->payload())->assertCreated()->assertJsonPath('data.code', 'manufacturer');
        $this->postJson('/api/v1/custom-field-definitions', $this->payload())->assertUnprocessable();
        $this->postJson('/api/v1/custom-field-definitions', array_replace($this->payload(), ['entity_type' => 'supplier_accounts']))->assertCreated();
        $this->postJson('/api/v1/custom-field-definitions', array_replace($this->payload(), ['code' => 'finish', 'data_type' => 'select']))->assertUnprocessable();
        $this->postJson('/api/v1/custom-field-definitions', array_replace($this->payload(), ['code' => 'finish', 'data_type' => 'select', 'options' => ['choices' => ['Matt', 'Gloss']]]))->assertCreated()->assertJsonPath('data.choices', ['Matt', 'Gloss']);
        $this->postJson('/api/v1/custom-field-definitions', array_replace($this->payload(), ['entity_type' => 'not_implemented']))->assertUnprocessable();
        $this->postJson('/api/v1/custom-field-definitions', array_replace($this->payload(), ['data_type' => 'reference']))->assertUnprocessable();
        $editor = PermissionedActor::grant(User::factory()->create(), ['inventory.item.manage']);
        $this->actingAs($editor)->getJson('/api/v1/custom-field-definitions?entity_type=items')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/custom-field-definitions?entity_type=supplier_accounts')->assertForbidden();
        $this->postJson('/api/v1/custom-field-definitions', $this->payload())->assertForbidden();
        $this->app->instance(CompanyContext::class, new class extends CompanyContext
        {
            public function id(): int
            {
                return 2;
            }
        });
        $this->getJson('/api/v1/custom-field-definitions?entity_type=items')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(3, CustomFieldDefinition::withoutGlobalScopes()->count());
    }
}

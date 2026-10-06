<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\CreateAssemblyVersion;
use App\Modules\Estimating\Actions\DeleteAssemblyVersion;
use App\Modules\Estimating\Actions\UpdateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class AssemblyVersionApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $sqm;

    private Uom $kg;

    private Item $pigment;

    private Assembly $assembly;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sqm = Uom::create(['code' => 'SQM', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1']);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'MATERIALS', 'name' => 'Materials', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'demo-pigment', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        $this->assembly = Assembly::create([
            'code' => 'ASM-1', 'name' => 'Wall Painting', 'work_uom_id' => $this->sqm->id,
            'margin_type' => 'MARKUP', 'margin_pct' => '20',
        ]);
    }

    /** @return array<string, mixed> */
    private function materialComponent(): array
    {
        return [
            'kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '0.500000',
            'uom_id' => $this->kg->id, 'wastage_pct' => '5.0000', 'multiplier' => '2.0000',
        ];
    }

    public function test_it_requires_authentication(): void
    {
        $this->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [])->assertUnauthorized();
    }

    public function test_a_version_can_be_created_with_a_material_component(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.version_no', 1)
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonCount(1, 'data.components');
    }

    public function test_a_labour_component_rejects_non_default_wastage_or_multiplier(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $hour = Uom::create(['code' => 'HR', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'LABOUR', 'name' => 'Labour', 'default_valuation_method' => 'AVCO']);
        $electrician = Item::create(['code' => 'demo-electrician', 'name' => 'Electrician', 'type' => ItemType::Labour, 'stock_uom_id' => $hour->id, 'category_id' => $category->id]);

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [[
                'kind' => 'LABOUR', 'item_id' => $electrician->id, 'qty_per_work_unit' => '1.000000',
                'uom_id' => $hour->id, 'wastage_pct' => '5.0000',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('components.0.wastage_pct');
    }

    public function test_a_subcontract_component_requires_a_rate_override(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [[
                'kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('components.0.rate_override');
    }

    public function test_a_subcontract_component_cannot_reference_an_item(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [[
                'kind' => 'SUBCONTRACT', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '1',
                'uom_id' => $this->sqm->id, 'rate_override' => '15.00',
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('components.0.item_id');
    }

    public function test_a_material_component_needs_an_item_or_an_application_rate(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [[
                'kind' => 'MATERIAL', 'qty_per_work_unit' => '1', 'uom_id' => $this->kg->id,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('components.0.item_id');
    }

    public function test_only_a_draft_version_can_be_activated_and_needs_at_least_one_component(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $empty = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [])
            ->assertCreated()->json('data');

        $this->postJson("/api/v1/assembly-versions/{$empty['id']}/activate")
            ->assertStatus(422)->assertJsonValidationErrors('components');

        $withComponent = $this->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/assembly-versions/{$withComponent['id']}/activate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $this->assertSame('ACTIVE', $this->assembly->refresh()->status->value);
    }

    public function test_activating_supersedes_the_previous_active_version(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $first = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/assembly-versions/{$first['id']}/activate")->assertOk();

        $second = $this->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/assembly-versions/{$second['id']}/activate")->assertOk();

        $this->assertSame('SUPERSEDED', AssemblyVersion::find($first['id'])->status->value);
        $this->assertSame('ACTIVE', AssemblyVersion::find($second['id'])->status->value);
    }

    public function test_an_active_version_cannot_be_edited_or_deleted(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $version = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/assembly-versions/{$version['id']}/activate")->assertOk();

        $this->patchJson("/api/v1/assembly-versions/{$version['id']}", [
            'components' => [$this->materialComponent()],
        ])->assertStatus(422);

        $this->deleteJson("/api/v1/assembly-versions/{$version['id']}")->assertStatus(422);
    }

    public function test_a_draft_version_can_be_updated_and_deleted(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $version = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');

        $this->patchJson("/api/v1/assembly-versions/{$version['id']}", ['notes' => 'Revised mix'])
            ->assertOk()->assertJsonPath('data.notes', 'Revised mix');

        $this->deleteJson("/api/v1/assembly-versions/{$version['id']}")->assertNoContent();
        $this->assertDatabaseMissing('assembly_versions', ['id' => $version['id']]);
    }

    public function test_show_assembly_can_include_versions_and_active_version(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $version = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/assembly-versions/{$version['id']}/activate")->assertOk();

        $this->getJson("/api/v1/assemblies/{$this->assembly->id}?include=activeVersion,activeVersion.components")
            ->assertOk()
            ->assertJsonPath('data.active_version.status', 'ACTIVE')
            ->assertJsonCount(1, 'data.active_version.components');
    }

    public function test_nested_component_rate_override_is_hidden_without_commercial_access(): void
    {
        $version = $this->assembly->versions()->create(['version_no' => 1]);
        $version->components()->create([
            'kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id,
            'rate_override' => '15.0000', 'sequence' => 0,
        ]);
        $viewer = PermissionedActor::grant(User::factory()->create(), ['estimating.recipe.view']);
        $url = "/api/v1/assemblies/{$this->assembly->id}?include=versions,versions.components";

        $this->actingAs($viewer)->getJson($url)->assertOk()
            ->assertJsonPath('data.versions.0.components.0.qty_per_work_unit', '1.000000')
            ->assertJsonMissingPath('data.versions.0.components.0.rate_override');

        $manager = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $this->actingAs($manager)->getJson($url)->assertOk()
            ->assertJsonPath('data.versions.0.components.0.rate_override', '15.0000');

        $costViewer = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);
        $this->actingAs($costViewer)->getJson($url)->assertOk()
            ->assertJsonPath('data.versions.0.components.0.rate_override', '15.0000');
    }

    public function test_component_quantities_are_withheld_from_users_without_recipe_access(): void
    {
        $version = $this->assembly->versions()->create(['version_no' => 1]);
        $version->components()->create([
            'kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id,
            'rate_override' => '15.0000', 'sequence' => 0,
        ]);
        $url = "/api/v1/assemblies/{$this->assembly->id}?include=versions,versions.components,activeVersion,activeVersion.components";

        // The assembly itself stays a shared lookup (code, name, group, versions); its component quantities are the recipe.
        foreach ([User::factory()->create(), PermissionedActor::grant(User::factory()->create(), ['inventory.stock.view'])] as $outsider) {
            $response = $this->actingAs($outsider)->getJson($url)->assertOk()->assertJsonPath('data.code', $this->assembly->code);
            $response->assertJsonMissingPath('data.versions.0.components');
            $this->assertStringNotContainsString('qty_per_work_unit', $response->getContent());
            $this->assertStringNotContainsString('15.0000', $response->getContent());
        }

        $planner = PermissionedActor::grant(User::factory()->create(), ['estimating.recipe.view']);
        $this->actingAs($planner)->getJson($url)->assertOk()->assertJsonCount(1, 'data.versions.0.components');
    }

    public function test_version_writes_require_assembly_manage(): void
    {
        $manager = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);
        $version = $this->actingAs($manager)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [
            'components' => [$this->materialComponent()],
        ])->assertCreated()->json('data');

        $viewer = User::factory()->create();
        $this->actingAs($viewer)->postJson("/api/v1/assemblies/{$this->assembly->id}/versions", [])->assertForbidden();
        $this->patchJson("/api/v1/assembly-versions/{$version['id']}", ['notes' => 'Changed'])->assertForbidden();
        $this->postJson("/api/v1/assembly-versions/{$version['id']}/activate")->assertForbidden();
        $this->deleteJson("/api/v1/assembly-versions/{$version['id']}")->assertForbidden();

        $this->assertDatabaseHas('assembly_versions', ['id' => $version['id'], 'status' => 'DRAFT', 'notes' => null]);
    }

    public function test_version_actions_reject_non_http_unpermitted_actor(): void
    {
        $viewer = User::factory()->create();
        $version = $this->assembly->versions()->create(['version_no' => 1]);
        $version->components()->create($this->materialComponent() + ['sequence' => 0]);

        foreach ([
            fn () => app(CreateAssemblyVersion::class)($this->assembly, [], $viewer),
            fn () => app(UpdateAssemblyVersion::class)($version, ['notes' => 'Changed'], $viewer),
            fn () => app(DeleteAssemblyVersion::class)($version, $viewer),
            fn () => app(ActivateAssemblyVersion::class)($version, $viewer),
        ] as $action) {
            try {
                $action();
                $this->fail('Expected the action to reject an unpermitted actor.');
            } catch (DomainInvariantException) {
                // Permission must be checked before any mutation.
            }
        }

        $this->assertDatabaseHas('assembly_versions', ['id' => $version->id, 'status' => 'DRAFT', 'notes' => null]);
    }
}

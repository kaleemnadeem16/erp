<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Actions\ArchiveFormulation;
use App\Modules\Estimating\Actions\CreateFormulation;
use App\Modules\Estimating\Actions\CreateFormulationVersion;
use App\Modules\Estimating\Actions\DeleteFormulationVersion;
use App\Modules\Estimating\Actions\UpdateFormulation;
use App\Modules\Estimating\Actions\UpdateFormulationVersion;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

class FormulationApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $litre;

    private Uom $kg;

    private Item $pigment;

    private Item $shade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1']);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'PAINT', 'name' => 'Paint', 'default_valuation_method' => 'AVCO']);
        $this->pigment = Item::create(['code' => 'PIGMENT', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        $this->shade = Item::create(['code' => 'SHADE', 'name' => 'Shade #4471', 'type' => ItemType::Finished, 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
    }

    private function customer(string $code): CustomerAccount
    {
        $party = Party::create(['code' => $code, 'name' => $code]);
        $receivables = ChartOfAccount::query()->firstOrCreate(['code' => '1200'], ['name' => 'Receivables', 'type' => AccountType::Asset]);

        return CustomerAccount::create(['party_id' => $party->id, 'code' => $code, 'payment_terms_days' => 30, 'receivable_account_id' => $receivables->id]);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/formulations')->assertUnauthorized();
    }

    public function test_a_formulation_can_be_created_with_lines_and_listed(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);

        $response = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_qty' => '1.000000', 'output_uom_id' => $this->litre->id,
            'lines' => [
                ['input_item_id' => $this->pigment->id, 'qty' => '0.500000', 'uom_id' => $this->kg->id, 'wastage_pct' => '10.0000'],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonCount(1, 'data.versions.0.lines');

        $this->getJson('/api/v1/formulations?filter[output_item_id]='.$this->shade->id)
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_version_history_can_include_human_readable_item_and_unit_details(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.5', 'uom_id' => $this->kg->id]],
        ])->json('data');

        $this->getJson("/api/v1/formulations/{$formulation['id']}?include=versions.lines.inputItem,versions.lines.uom")
            ->assertOk()
            ->assertJsonPath('data.versions.0.lines.0.input_item.code', 'pigment')
            ->assertJsonPath('data.versions.0.lines.0.input_item.name', 'Pigment')
            ->assertJsonPath('data.versions.0.lines.0.uom.code', 'kg')
            ->assertJsonPath('data.versions.0.lines.0.uom.name', 'Kilogram');
    }

    public function test_formulation_units_must_match_their_items_dimensions(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);

        $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'BAD-OUTPUT-UOM', 'name' => 'Bad output',
            'output_uom_id' => $this->kg->id,
        ])->assertUnprocessable()->assertJsonPath('errors.output_uom_id.0', "Output UoM must have the same dimension as the item's stock UoM.");

        $response = $this->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'BAD-LINE-UOM', 'name' => 'Bad line',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.5', 'uom_id' => $this->litre->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['lines.0.uom_id']);

        $this->assertSame(
            "Ingredient UoM must have the same dimension as the item's stock UoM.",
            $response->json('errors')['lines.0.uom_id'][0],
        );
    }

    public function test_a_version_can_be_activated_and_superseded_by_the_next(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.5', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $versionId = $formulation['versions'][0]['id'];

        $this->postJson("/api/v1/formulation-versions/{$versionId}/activate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $this->getJson("/api/v1/formulations/{$formulation['id']}")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        // Create and activate version 2 — version 1 should become SUPERSEDED.
        $version2 = $this->postJson("/api/v1/formulations/{$formulation['id']}/versions", [
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.6', 'uom_id' => $this->kg->id]],
        ])->assertCreated()->json('data');

        $this->postJson("/api/v1/formulation-versions/{$version2['id']}/activate")->assertOk();

        $this->getJson("/api/v1/formulations/{$formulation['id']}?include=versions")
            ->assertOk()
            ->assertJsonPath('data.versions.0.status', 'ACTIVE')
            ->assertJsonPath('data.versions.0.version_no', 2)
            ->assertJsonPath('data.versions.1.status', 'SUPERSEDED')
            ->assertJsonPath('data.versions.1.version_no', 1);
    }

    public function test_an_active_versions_lines_cannot_be_changed(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.5', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $versionId = $formulation['versions'][0]['id'];
        $this->postJson("/api/v1/formulation-versions/{$versionId}/activate")->assertOk();

        $this->patchJson("/api/v1/formulation-versions/{$versionId}", [
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.9', 'uom_id' => $this->kg->id]],
        ])->assertUnprocessable();
    }

    public function test_activating_an_empty_version_is_rejected(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
        ])->json('data');
        $versionId = $formulation['versions'][0]['id'];

        $this->postJson("/api/v1/formulation-versions/{$versionId}/activate")->assertUnprocessable();
    }

    public function test_a_draft_version_can_be_deleted_but_an_active_one_cannot(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.5', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $versionId = $formulation['versions'][0]['id'];

        $version2 = $this->postJson("/api/v1/formulations/{$formulation['id']}/versions", [
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.6', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $this->deleteJson("/api/v1/formulation-versions/{$version2['id']}")->assertNoContent();

        $this->postJson("/api/v1/formulation-versions/{$versionId}/activate")->assertOk();
        $this->deleteJson("/api/v1/formulation-versions/{$versionId}")->assertUnprocessable();
    }

    public function test_resolve_prefers_the_customers_own_formulation_over_the_default(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $customer = $this->customer('cus-alpha');

        $defaultFormulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE-DEFAULT', 'name' => 'Shade default',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.5', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $this->postJson("/api/v1/formulation-versions/{$defaultFormulation['versions'][0]['id']}/activate")->assertOk();

        $customerFormulation = $this->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE-CUS', 'name' => 'Shade for Alpha',
            'output_uom_id' => $this->litre->id, 'customer_id' => $customer->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '0.9', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $this->postJson("/api/v1/formulation-versions/{$customerFormulation['versions'][0]['id']}/activate")->assertOk();

        $this->getJson("/api/v1/formulations/resolve?output_item_id={$this->shade->id}&customer_id={$customer->id}")
            ->assertOk()->assertJsonPath('data.id', $customerFormulation['id']);

        $this->getJson("/api/v1/formulations/resolve?output_item_id={$this->shade->id}")
            ->assertOk()->assertJsonPath('data.id', $defaultFormulation['id']);
    }

    public function test_resolve_fails_loudly_with_no_formulation(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);

        $this->actingAs($user)->getJson("/api/v1/formulations/resolve?output_item_id={$this->shade->id}")
            ->assertUnprocessable();
    }

    public function test_cost_breakdown_returns_the_resolved_total(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), [
            'estimating.formulation.manage', 'estimating.internal_cost.view',
        ]);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
            'lines' => [['input_item_id' => $this->pigment->id, 'qty' => '1.000000', 'uom_id' => $this->kg->id]],
        ])->json('data');
        $versionId = $formulation['versions'][0]['id'];
        $this->postJson("/api/v1/formulation-versions/{$versionId}/activate")->assertOk();

        $managerWithoutCost = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $this->actingAs($managerWithoutCost)
            ->getJson("/api/v1/formulation-versions/{$versionId}/cost-breakdown")
            ->assertForbidden();

        $this->actingAs($user)->getJson("/api/v1/formulation-versions/{$versionId}/cost-breakdown")
            ->assertOk()
            ->assertJsonPath('data.cost_per_output_unit', '0.0000')
            ->assertJsonCount(1, 'data.lines');
    }

    public function test_output_item_cannot_be_changed_through_the_update_endpoint(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
        ])->json('data');
        $otherItem = Item::create(['code' => 'OTHER', 'name' => 'Other', 'type' => ItemType::Finished, 'stock_uom_id' => $this->litre->id, 'category_id' => $this->shade->category_id]);

        $this->patchJson("/api/v1/formulations/{$formulation['id']}", [
            'output_item_id' => $otherItem->id, 'name' => 'Renamed',
        ])->assertOk()
            ->assertJsonPath('data.output_item_id', $this->shade->id)
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_destroy_archives_the_formulation(): void
    {
        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);
        $formulation = $this->actingAs($user)->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE', 'name' => 'Shade #4471',
            'output_uom_id' => $this->litre->id,
        ])->json('data');

        $this->deleteJson("/api/v1/formulations/{$formulation['id']}")->assertNoContent();

        $this->getJson("/api/v1/formulations/{$formulation['id']}")
            ->assertOk()->assertJsonPath('data.status', 'ARCHIVED');
    }

    public function test_formulation_and_version_writes_require_manage_while_reads_remain_available(): void
    {
        $formulation = Formulation::create([
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE',
            'name' => 'Shade #4471', 'output_uom_id' => $this->litre->id,
        ]);
        $version = $formulation->versions()->create(['version_no' => 1])->refresh();
        $version->lines()->create([
            'input_item_id' => $this->pigment->id, 'qty' => '0.500000',
            'uom_id' => $this->kg->id, 'sequence' => 0,
        ]);
        $reader = User::factory()->create();

        $this->actingAs($reader)->getJson('/api/v1/formulations')->assertOk();
        $this->getJson("/api/v1/formulations/{$formulation->id}?include=versions.lines")->assertOk();
        $this->postJson('/api/v1/formulations', [
            'output_item_id' => $this->shade->id, 'code' => 'OTHER',
            'name' => 'Other', 'output_uom_id' => $this->litre->id,
        ])->assertForbidden();
        $this->patchJson("/api/v1/formulations/{$formulation->id}", ['name' => 'Changed'])->assertForbidden();
        $this->deleteJson("/api/v1/formulations/{$formulation->id}")->assertForbidden();
        $this->postJson("/api/v1/formulations/{$formulation->id}/versions", [])->assertForbidden();
        $this->patchJson("/api/v1/formulation-versions/{$version->id}", ['notes' => 'Changed'])->assertForbidden();
        $this->deleteJson("/api/v1/formulation-versions/{$version->id}")->assertForbidden();
        $this->postJson("/api/v1/formulation-versions/{$version->id}/activate")->assertForbidden();

        $this->assertSame('Shade #4471', $formulation->fresh()->name);
        $this->assertSame('DRAFT', $version->fresh()->status->value);
        $this->assertSame(1, $formulation->versions()->count());
        $this->assertDatabaseMissing('formulations', ['code' => 'OTHER']);
    }

    public function test_formulation_actions_reject_an_unprivileged_non_http_caller(): void
    {
        $formulation = Formulation::create([
            'output_item_id' => $this->shade->id, 'code' => 'F-SHADE',
            'name' => 'Shade #4471', 'output_uom_id' => $this->litre->id,
        ]);
        $version = $formulation->versions()->create(['version_no' => 1])->refresh();
        $version->lines()->create([
            'input_item_id' => $this->pigment->id, 'qty' => '0.500000',
            'uom_id' => $this->kg->id, 'sequence' => 0,
        ]);
        $reader = User::factory()->create();
        $actions = [
            fn () => app(CreateFormulation::class)([], $reader),
            fn () => app(UpdateFormulation::class)($formulation, ['name' => 'Changed'], $reader),
            fn () => app(ArchiveFormulation::class)($formulation, $reader),
            fn () => app(CreateFormulationVersion::class)($formulation, [], $reader),
            fn () => app(UpdateFormulationVersion::class)($version, ['notes' => 'Changed'], $reader),
            fn () => app(DeleteFormulationVersion::class)($version, $reader),
            fn () => app(ActivateFormulationVersion::class)($version, $reader),
        ];

        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('An unprivileged Action caller was accepted.');
            } catch (DomainInvariantException $exception) {
                $this->assertStringContainsString('not permitted', $exception->getMessage());
            }
        }

        $this->assertSame('Shade #4471', $formulation->fresh()->name);
        $this->assertSame('DRAFT', $version->fresh()->status->value);
        $this->assertSame(1, $formulation->versions()->count());
    }
}

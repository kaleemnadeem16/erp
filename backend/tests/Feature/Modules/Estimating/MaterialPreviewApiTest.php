<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Support\AccountType;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MaterialPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    private Uom $sqm;

    private Uom $litre;

    private Uom $kg;

    private Item $paint;

    private Item $pigment;

    private Assembly $assembly;

    private int $smoothValueId;

    private int $exactRateId;

    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('estimating.material_preview.run');
        Permission::findOrCreate('estimating.internal_cost.view');

        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $category = Category::create(['code' => 'PAINT', 'name' => 'Paint', 'default_valuation_method' => 'AVCO']);
        $this->paint = Item::create(['code' => 'PAINT-A', 'name' => 'Paint A', 'type' => ItemType::SemiFinished, 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
        $this->pigment = Item::create(['code' => 'PIGMENT', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        AvcoPool::create([
            'item_id' => $this->pigment->id,
            'scope' => AvcoPoolScope::CompanyItem,
            'qty_on_hand' => '100',
            'inventory_value' => '300',
            'avg_unit_cost' => '3.0000',
            'base_avg_unit_cost' => '3.0000',
            'last_valid_avg_unit_cost' => '3.0000',
            'status' => AvcoPoolStatus::Active,
        ]);

        $party = Party::create(['code' => 'CUSTOMER-A', 'name' => 'Customer A']);
        $receivables = ChartOfAccount::create(['code' => '1200', 'name' => 'Receivables', 'type' => AccountType::Asset]);
        $customer = CustomerAccount::create([
            'party_id' => $party->id,
            'code' => 'CUSTOMER-A',
            'payment_terms_days' => 30,
            'receivable_account_id' => $receivables->id,
        ]);
        $this->customerId = $customer->id;

        $defaultFormulation = $this->formulation('FORM-DEFAULT', null, '1.000000');
        $this->formulation('FORM-CUSTOMER', $this->customerId, '2.000000');

        $surface = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface', 'is_required' => true]);
        $this->smoothValueId = $surface->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth'])->id;
        $defaultRate = ApplicationRate::create([
            'item_id' => $this->paint->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.100000', 'uom_id' => $this->litre->id, 'is_default' => true,
        ]);
        $exactRate = ApplicationRate::create([
            'item_id' => $this->paint->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.200000', 'uom_id' => $this->litre->id,
        ]);
        $exactRate->dimensionValues()->sync([$this->smoothValueId]);
        $this->exactRateId = $exactRate->id;

        $this->assembly = Assembly::create([
            'code' => 'PAINT-WALL', 'name' => 'Paint wall', 'work_uom_id' => $this->sqm->id,
            'margin_type' => 'MARKUP', 'margin_pct' => '0',
        ]);
        $version = $this->assembly->versions()->create(['version_no' => 1])->refresh();
        $version->components()->create([
            'kind' => 'MATERIAL', 'application_rate_id' => $defaultRate->id,
            'formulation_id' => $defaultFormulation->id,
            'qty_per_work_unit' => '1.000000', 'uom_id' => $this->litre->id,
            'sequence' => 0,
        ]);
        (new ActivateAssemblyVersion)($version);
    }

    public function test_preview_requires_the_run_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload())
            ->assertForbidden();
    }

    public function test_preview_resolves_selected_rate_and_customer_formulation_into_leaf_requirements(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.material_preview.run');

        $response = $this->actingAs($user)->postJson(
            "/api/v1/assemblies/{$this->assembly->id}/material-preview",
            $this->payload(),
        );

        $response->assertOk();
        $response->assertJsonPath('data.context.resolved_work_quantity', '10.000000000');
        $response->assertJsonPath('data.material_components.0.application_rate.resolved_id', $this->exactRateId);
        $response->assertJsonPath('data.material_components.0.application_rate.method', 'EXACT_MATCH');
        $response->assertJsonPath('data.material_components.0.formulation.customer_id', $this->customerId);
        // 10 sqm × 0.2 L/sqm × 2 kg/L customer recipe = 4 kg pigment.
        $response->assertJsonPath('data.leaf_requirements.0.item.code', 'pigment');
        $response->assertJsonPath('data.leaf_requirements.0.standard_quantity', '4.000000000');
        $response->assertJsonPath('data.cost_breakdown', null);
        $response->assertJsonCount(2, 'data.limitations');
    }

    public function test_internal_cost_is_returned_only_with_the_separate_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.material_preview.run', 'estimating.internal_cost.view');

        $response = $this->actingAs($user)->postJson(
            "/api/v1/assemblies/{$this->assembly->id}/material-preview",
            $this->payload(),
        );

        $response->assertOk();
        $response->assertJsonPath('data.cost_breakdown.material_cost', '12.0000');
        $response->assertJsonPath('data.cost_breakdown.total_cost', '12.0000');
    }

    public function test_preview_is_read_only(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.material_preview.run');
        $before = [
            'assemblies' => $this->assembly->newQuery()->count(),
            'versions' => $this->assembly->versions()->count(),
            'rates' => ApplicationRate::query()->count(),
            'formulations' => Formulation::query()->count(),
        ];

        $this->actingAs($user)->postJson(
            "/api/v1/assemblies/{$this->assembly->id}/material-preview",
            $this->payload(),
        )->assertOk();

        $this->assertSame($before, [
            'assemblies' => $this->assembly->newQuery()->count(),
            'versions' => $this->assembly->versions()->count(),
            'rates' => ApplicationRate::query()->count(),
            'formulations' => Formulation::query()->count(),
        ]);
    }

    private function formulation(string $code, ?int $customerId, string $pigmentQty): Formulation
    {
        $formulation = Formulation::create([
            'output_item_id' => $this->paint->id,
            'code' => $code,
            'name' => $code,
            'output_qty' => '1.000000',
            'output_uom_id' => $this->litre->id,
            'customer_id' => $customerId,
        ]);
        $version = $formulation->versions()->create(['version_no' => 1])->refresh();
        $version->lines()->create([
            'input_item_id' => $this->pigment->id,
            'qty' => $pigmentQty,
            'uom_id' => $this->kg->id,
            'sequence' => 0,
        ]);
        (new ActivateFormulationVersion(new FormulationCycleGuard))($version);

        return $formulation->refresh();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'work_quantity' => '10',
            'work_uom_id' => $this->sqm->id,
            'customer_id' => $this->customerId,
            'dimension_value_ids' => [$this->smoothValueId],
        ];
    }
}

<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Company;
use App\Models\Currency;
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
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\LocationType;
use App\Modules\Parties\Models\CustomerAccount;
use App\Modules\Parties\Models\Party;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /** @var array<string, Location> */
    private array $locations = [];

    private int $sourceId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->setPermissionsTeamId(1);
        Permission::findOrCreate('estimating.material_preview.run');
        Permission::findOrCreate('estimating.internal_cost.view');
        Permission::findOrCreate('estimating.inventory_availability.view');

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
        $response->assertJsonPath('data.inventory', null);
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

    public function test_inventory_is_omitted_and_its_inputs_are_refused_without_the_availability_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.material_preview.run', 'estimating.internal_cost.view');
        $this->stockPigment('a', '5');

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());
        $response->assertOk();
        // Hidden availability is absent, not reported as zero stock or a shortage.
        $response->assertJsonPath('data.inventory', null);

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'inventory_scope' => ['warehouse_ids' => [$this->location('a')->warehouse_id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('inventory_scope');

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'selection_strategy' => 'FEWEST_PACKAGES',
        ])->assertUnprocessable()->assertJsonValidationErrors('selection_strategy');
    }

    public function test_inventory_proposal_reports_usable_stock_coverage_and_a_deterministic_recommendation(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('a', '3');
        $this->stockPigment('b', '2');

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.inventory.tracking_mode', 'QUANTITY_ONLY');
        $response->assertJsonPath('data.inventory.strategy.code', 'MINIMISE_WASTE');
        $response->assertJsonPath('data.inventory.strategy.source', 'COMPANY_DEFAULT');
        $response->assertJsonPath('data.inventory.scope.narrowed', false);
        $response->assertJsonPath('data.inventory.items.0.item.code', 'pigment');
        // 10 sqm × 0.2 L/sqm × 2 kg/L = 4 kg needed; 3 + 2 kg usable across two warehouses.
        $response->assertJsonPath('data.inventory.items.0.required_quantity', '4.000000000');
        $response->assertJsonPath('data.inventory.items.0.on_hand_quantity', '5.0000');
        $response->assertJsonPath('data.inventory.items.0.usable_quantity', '5.0000');
        $response->assertJsonPath('data.inventory.items.0.covered_quantity', '4.000000000');
        $response->assertJsonPath('data.inventory.items.0.shortage_quantity', '0.000000000');
        $response->assertJsonPath('data.inventory.items.0.remaining_after_use_quantity', '1.000000000');
        // Equal cost everywhere → the larger balance first, so fewer locations are used.
        $response->assertJsonPath('data.inventory.items.0.recommendation.pick_count', 2);
        $response->assertJsonPath('data.inventory.items.0.recommendation.lines.0.warehouse.code', 'a');
        $response->assertJsonPath('data.inventory.items.0.recommendation.lines.0.quantity', '3.0000');
        $response->assertJsonPath('data.inventory.items.0.recommendation.lines.1.quantity', '1.000000000');
        $response->assertJsonCount(2, 'data.inventory.items.0.alternatives');
        $response->assertJsonCount(2, 'data.inventory.items.0.balances');
        // The preview never asserts a reservation.
        $this->assertStringContainsString('reserves nothing', implode(' ', $response->json('data.inventory.limitations')));
    }

    public function test_the_inventory_scope_can_narrow_the_stock_considered_and_a_shortage_is_reported(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('a', '3');
        $this->stockPigment('b', '2');

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'inventory_scope' => ['warehouse_ids' => [$this->location('a')->warehouse_id]],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.inventory.scope.narrowed', true);
        $response->assertJsonPath('data.inventory.items.0.usable_quantity', '3.0000');
        $response->assertJsonPath('data.inventory.items.0.covered_quantity', '3.0000');
        $response->assertJsonPath('data.inventory.items.0.shortage_quantity', '1.000000000');
        $response->assertJsonCount(1, 'data.inventory.items.0.recommendation.lines');
        $this->assertStringContainsString('short by 1 kg', implode(' ', $response->json('data.inventory.warnings')));
    }

    public function test_a_foreign_or_unknown_warehouse_or_location_is_rejected(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'inventory_scope' => ['warehouse_ids' => [999999], 'location_ids' => [999999]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['inventory_scope.warehouse_ids.0', 'inventory_scope.location_ids.0']);
    }

    public function test_a_what_if_strategy_is_reflected_and_the_other_presets_are_offered_as_alternatives(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('a', '3');
        $this->stockPigment('b', '2');

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'selection_strategy' => 'FEWEST_PACKAGES',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.inventory.strategy.code', 'FEWEST_PACKAGES');
        $response->assertJsonPath('data.inventory.strategy.source', 'REQUEST_OVERRIDE');
        $response->assertJsonPath('data.inventory.items.0.recommendation.strategy.code', 'FEWEST_PACKAGES');
        $this->assertEqualsCanonicalizing(
            ['MINIMISE_WASTE', 'LOWEST_INTERNAL_COST'],
            array_column(array_column($response->json('data.inventory.items.0.alternatives'), 'strategy'), 'code'),
        );

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'selection_strategy' => 'CHEAPEST_MAYBE',
        ])->assertUnprocessable()->assertJsonValidationErrors('selection_strategy');
    }

    public function test_stock_in_a_location_that_cannot_hold_usable_stock_is_explained_not_silently_dropped(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('q', '4', LocationType::Quarantine);

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.inventory.items.0.on_hand_quantity', '4.0000');
        $response->assertJsonPath('data.inventory.items.0.unusable_location_quantity', '4.0000');
        $response->assertJsonPath('data.inventory.items.0.usable_quantity', '0.0000');
        $response->assertJsonPath('data.inventory.items.0.shortage_quantity', '4.000000000');
        $response->assertJsonPath('data.inventory.items.0.balances.0.location_usable', false);
        $response->assertJsonPath('data.inventory.items.0.recommendation.lines', []);
        $this->assertContains('QUARANTINE', $response->json('data.inventory.scope.excluded_location_types'));
    }

    public function test_internal_cost_evidence_needs_its_own_permission_even_with_availability(): void
    {
        $this->stockPigment('a', '3');
        $this->stockPigment('b', '2');
        $withoutCost = $this->userWith('estimating.inventory_availability.view');
        $withCost = $this->userWith('estimating.inventory_availability.view', 'estimating.internal_cost.view');

        $hidden = $this->actingAs($withoutCost)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());
        $hidden->assertOk();
        // Availability shows; every cost figure is hidden — null, not zero — while cost still ranks the balances.
        $hidden->assertJsonPath('data.inventory.items.0.recommendation.estimated_cost', null);
        $hidden->assertJsonPath('data.inventory.items.0.recommendation.lines.0.unit_cost', null);
        $hidden->assertJsonPath('data.inventory.items.0.recommendation.lines.0.cost_method', null);
        $hidden->assertJsonPath('data.inventory.items.0.recommendation.lines.0.line_cost', null);

        $shown = $this->actingAs($withCost)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());
        $shown->assertOk();
        // 4 kg at the company pool's 3.0000/kg.
        $shown->assertJsonPath('data.inventory.items.0.recommendation.estimated_cost', '12.0000');
        $shown->assertJsonPath('data.inventory.items.0.recommendation.lines.0.unit_cost', '3.0000');
        $shown->assertJsonPath('data.inventory.items.0.recommendation.lines.0.cost_method', 'AVCO_POOL');
        $shown->assertJsonPath('data.inventory.items.0.recommendation.lines.0.line_cost', '9.0000');
    }

    public function test_a_non_stocked_leaf_item_gets_no_availability_rather_than_a_false_shortage(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->pigment->update(['is_stocked' => false]);

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.inventory.items.0.is_stocked', false);
        $response->assertJsonPath('data.inventory.items.0.usable_quantity', null);
        $response->assertJsonPath('data.inventory.items.0.shortage_quantity', null);
        $response->assertJsonPath('data.inventory.items.0.recommendation', null);
    }

    public function test_the_fingerprint_moves_with_stock_only_for_someone_allowed_to_see_stock(): void
    {
        $blind = User::factory()->create();
        $blind->givePermissionTo('estimating.material_preview.run');
        $sighted = $this->userWith('estimating.inventory_availability.view');
        $url = "/api/v1/assemblies/{$this->assembly->id}/material-preview";
        $this->stockPigment('a', '3');

        $blindBefore = $this->actingAs($blind)->postJson($url, $this->payload())->json('data.evidence_fingerprint');
        $sightedBefore = $this->actingAs($sighted)->postJson($url, $this->payload())->json('data.evidence_fingerprint');

        $this->stockPigment('a', '1');

        $this->assertSame($blindBefore, $this->actingAs($blind)->postJson($url, $this->payload())->json('data.evidence_fingerprint'));
        $this->assertNotSame($sightedBefore, $this->actingAs($sighted)->postJson($url, $this->payload())->json('data.evidence_fingerprint'));
    }

    public function test_the_inventory_proposal_is_read_only(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.internal_cost.view');
        $this->stockPigment('a', '3');
        $counts = fn (): array => array_map(
            fn (string $table): int => DB::table($table)->count(),
            ['stock_moves', 'stock_layers', 'avco_pools', 'stock_valuation_entries', 'negative_stock_shortages', 'stock_batches'],
        );
        $before = $counts();

        $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload() + [
            'selection_strategy' => 'LOWEST_INTERNAL_COST',
        ])->assertOk();

        $this->assertSame($before, $counts());
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('estimating.material_preview.run', ...$permissions);

        return $user;
    }

    /** A warehouse's single location, created once per test and reused by code. */
    private function location(string $warehouseCode, ?LocationType $type = null): Location
    {
        if (! isset($this->locations[$warehouseCode])) {
            $this->locations[$warehouseCode] = app(CreateWarehouse::class)([
                'code' => $warehouseCode, 'name' => strtoupper($warehouseCode), 'location_code' => 'main', 'location_name' => 'Main',
            ])->locations()->sole();
            if ($type !== null) {
                $this->locations[$warehouseCode]->update(['type' => $type]);
            }
        }

        return $this->locations[$warehouseCode];
    }

    /** Receives pigment at the company pool's own 3.0000/kg, so its average cost stays exactly 3. */
    private function stockPigment(string $warehouseCode, string $qty, ?LocationType $type = null): void
    {
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$']);
        Company::query()->findOrFail(1)->update(['base_currency_id' => $currency->id]);
        // The shared fixture's pigment is a bare recipe ingredient; stocking it needs the item to be
        // stocked and to carry an inventory account for the valuation entry.
        if (! $this->pigment->is_stocked) {
            $this->pigment->update([
                'is_stocked' => true,
                'inventory_account_id' => ChartOfAccount::create(['code' => 'inv', 'name' => 'Inventory', 'type' => AccountType::Asset])->id,
            ]);
        }

        app(PostInventoryMovement::class)([
            'item_id' => $this->pigment->id, 'location_id' => $this->location($warehouseCode, $type)->id,
            'qty' => $qty, 'uom_id' => $this->kg->id, 'unit_cost' => '3.0000', 'base_unit_cost' => '3.0000',
            'currency_id' => $currency->id, 'fx_rate' => '1',
            'source_type' => 'TEST_RECEIPT', 'source_id' => ++$this->sourceId, 'moved_at' => now(),
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

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
use App\Modules\Estimating\Actions\SetMaterialSelectionPolicy;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Actions\CreateWarehouse;
use App\Modules\Inventory\Actions\PostInventoryMovement;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemQuantityUnit;
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
use Tests\Support\PermissionedActor;
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
        Permission::findOrCreate('estimating.stock_mix.choose');

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
        (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));
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
        $response->assertJsonPath('data.inventory.strategy.source', 'BUILT_IN_DEFAULT');
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

    public function test_a_marked_location_counts_and_the_balance_says_the_mark_not_the_type_decided(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('q', '4', LocationType::Quarantine);
        $this->stockPigment('m', '3');
        Location::query()->where('type', LocationType::Quarantine)->update(['counts_as_usable' => true]);

        $response = $this->actingAs($user)->postJson("/api/v1/assemblies/{$this->assembly->id}/material-preview", $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.inventory.items.0.usable_quantity', '7.0000');
        $response->assertJsonPath('data.inventory.items.0.unusable_location_quantity', '0.0000');
        /** @var list<array{location_usable_source: string}> $balances */
        $balances = $response->json('data.inventory.items.0.balances');
        $sources = array_column($balances, 'location_usable_source');
        $this->assertEqualsCanonicalizing(['MARKED', 'TYPE'], $sources);
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

    // ---- planned adjustments and variance (ADR 0054 through ADR 0058) ----

    public function test_without_adjustments_there_is_no_variance_block(): void
    {
        $this->actingAs($this->userWith())->postJson($this->url(), $this->payload())->assertOk()->assertJsonPath('data.variance', null);
    }

    public function test_with_no_policy_any_difference_needs_a_reason_and_the_reason_makes_it_adoptable(): void
    {
        $reason = MaterialVarianceReason::create(['code' => 'porous', 'name' => 'Porous surface']);
        $user = $this->userWith();

        $missing = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);
        $missing->assertOk();
        $missing->assertJsonPath('data.variance.items.0.standard_quantity', '4.000000000');
        $missing->assertJsonPath('data.variance.items.0.planned_quantity', '5.000000000');
        $missing->assertJsonPath('data.variance.items.0.delta_percent', '25.000000');
        $missing->assertJsonPath('data.variance.items.0.effect', 'REASON_REQUIRED');
        $missing->assertJsonPath('data.variance.items.0.reason_status', 'MISSING');
        $missing->assertJsonPath('data.variance.items.0.can_adopt', false);
        $missing->assertJsonPath('data.variance.items.0.policy', null);
        $missing->assertJsonPath('data.variance.summary', ['adjusted_count' => 1, 'needs_reason_count' => 1, 'blocked_count' => 0, 'needs_approval_count' => 0, 'can_adopt' => false, 'cost_impact' => null, 'cost_evidence_missing_count' => null]);

        $given = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5', ['reason_id' => $reason->id, 'note' => 'Bare plaster'])]]);
        $given->assertJsonPath('data.variance.items.0.reason_status', 'PROVIDED');
        $given->assertJsonPath('data.variance.items.0.reason.code', 'porous');
        $given->assertJsonPath('data.variance.items.0.note', 'Bare plaster');
        $given->assertJsonPath('data.variance.items.0.can_adopt', true);
        $given->assertJsonPath('data.variance.summary.can_adopt', true);
    }

    public function test_an_unchanged_or_zero_plan_is_judged_correctly(): void
    {
        $user = $this->userWith();

        $same = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('4')]]);
        $same->assertJsonPath('data.variance.items.0.effect', null)->assertJsonPath('data.variance.items.0.can_adopt', true);
        $same->assertJsonPath('data.variance.summary.adjusted_count', 0);

        $zero = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('0')]]);
        $zero->assertJsonPath('data.variance.items.0.delta_percent', '-100.000000')->assertJsonPath('data.variance.items.0.effect', 'REASON_REQUIRED');
    }

    public function test_the_resolved_policy_decides_and_a_blocked_plan_is_not_adoptable(): void
    {
        $this->activePolicy([['OVER', 'PERCENT', '50', null, 'BLOCKED']]);
        $user = $this->userWith();

        $small = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);
        $small->assertJsonPath('data.variance.items.0.effect', 'AUDIT_ONLY')->assertJsonPath('data.variance.items.0.can_adopt', true);
        $small->assertJsonPath('data.variance.items.0.policy.scope_type', 'COMPANY')->assertJsonPath('data.variance.items.0.policy.method', 'COMPANY');

        $big = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('7')]]);
        $big->assertJsonPath('data.variance.items.0.effect', 'BLOCKED')->assertJsonPath('data.variance.items.0.reason_status', 'NOT_ACCEPTED');
        $big->assertJsonPath('data.variance.items.0.can_adopt', false)->assertJsonPath('data.variance.summary.blocked_count', 1);
        $big->assertJsonPath('data.variance.items.0.matched.0.observed', '75');
    }

    public function test_a_plan_may_be_entered_in_any_convertible_unit(): void
    {
        $gram = Uom::create(['code' => 'g', 'name' => 'Gram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '0.001']);

        $response = $this->actingAs($this->userWith())->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5000', ['uom_id' => $gram->id])]]);

        $response->assertOk()->assertJsonPath('data.variance.items.0.planned_quantity', '5.000000000');
        $response->assertJsonPath('data.variance.items.0.entered', ['quantity' => '5000', 'uom_code' => 'g']);
    }

    public function test_unusable_adjustments_fail_loudly_with_the_field_that_is_wrong(): void
    {
        $user = $this->userWith();
        $retired = MaterialVarianceReason::create(['code' => 'old', 'name' => 'Old', 'active' => false]);
        $stranger = Item::create(['code' => 'STRANGER', 'name' => 'Stranger', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->pigment->category_id]);

        $cases = [
            'an item the assembly does not require' => [$this->adjust('5', ['item_id' => $stranger->id]), 'planned_adjustments.0.item_id'],
            'a unit that does not convert' => [$this->adjust('5', ['uom_id' => $this->litre->id]), 'planned_adjustments.0.uom_id'],
            'a retired reason' => [$this->adjust('5', ['reason_id' => $retired->id]), 'planned_adjustments.0.reason_id'],
            'a negative quantity' => [$this->adjust('-1'), 'planned_adjustments.0.planned_quantity'],
            'an unknown reason' => [$this->adjust('5', ['reason_id' => 99999]), 'planned_adjustments.0.reason_id'],
        ];
        foreach ($cases as [$adjustment, $key]) {
            $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$adjustment]])
                ->assertUnprocessable()->assertJsonValidationErrors($key);
        }

        $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5'), $this->adjust('6')]])
            ->assertUnprocessable()->assertJsonValidationErrors('planned_adjustments.0.item_id');
    }

    public function test_quantity_precision_is_enforced_not_rounded(): void
    {
        $this->pigment->update(['quantity_precision' => '1.000000000']);

        $this->actingAs($this->userWith())->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('4.5')]])
            ->assertUnprocessable()->assertJsonValidationErrors('planned_adjustments.0.planned_quantity');
        $this->actingAs($this->userWith())->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]])->assertOk();
    }

    public function test_what_a_cost_band_observed_is_hidden_without_the_cost_capability(): void
    {
        // 1 kg over × 3 per kg = 3, at or beyond the band's 2.
        $this->activePolicy([['OVER', 'COST_AMOUNT', '2', null, 'REASON_REQUIRED']]);

        $blind = $this->actingAs($this->userWith())->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);
        $sighted = $this->actingAs($this->userWith('estimating.internal_cost.view'))->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);

        // The band still applies for both — cost decides the effect — but only one caller sees the amount.
        $blind->assertJsonPath('data.variance.items.0.effect', 'REASON_REQUIRED')->assertJsonPath('data.variance.items.0.matched.0.observed', null);
        $blind->assertJsonPath('data.variance.items.0.matched.0.measure', 'COST_AMOUNT');
        $sighted->assertJsonPath('data.variance.items.0.effect', 'REASON_REQUIRED')->assertJsonPath('data.variance.items.0.matched.0.observed', '3');
        $this->assertStringNotContainsString('"observed":"3"', $blind->getContent());
    }

    public function test_a_plan_shows_what_it_would_cost_against_the_standard_only_with_the_cost_capability(): void
    {
        // 4 kg standard at 3 per kg = 12; planning 5 kg = 15; the plan costs 3 more.
        $sighted = $this->actingAs($this->userWith('estimating.internal_cost.view'))->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);
        $blind = $this->actingAs($this->userWith())->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);

        $sighted->assertJsonPath('data.variance.items.0.cost.status', 'AVAILABLE');
        $this->assertEquals(12, (float) $sighted->json('data.variance.items.0.cost.standard'));
        $this->assertEquals(15, (float) $sighted->json('data.variance.items.0.cost.planned'));
        $this->assertEquals(3, (float) $sighted->json('data.variance.items.0.cost.delta'));
        $this->assertEquals(3, (float) $sighted->json('data.variance.summary.cost_impact'));
        $sighted->assertJsonPath('data.variance.summary.cost_evidence_missing_count', 0);

        // Hidden is hidden, not zero: every amount is null and the status says why.
        $blind->assertJsonPath('data.variance.items.0.cost.status', 'HIDDEN')
            ->assertJsonPath('data.variance.items.0.cost.planned', null)
            ->assertJsonPath('data.variance.items.0.cost.delta', null)
            ->assertJsonPath('data.variance.summary.cost_impact', null)
            ->assertJsonPath('data.variance.summary.cost_evidence_missing_count', null);
        $this->assertStringNotContainsString('15.0000', $blind->getContent());
    }

    public function test_a_material_without_cost_evidence_has_no_planned_cost_rather_than_a_zero_one(): void
    {
        AvcoPool::query()->where('item_id', $this->pigment->id)->delete();

        $response = $this->actingAs($this->userWith('estimating.internal_cost.view'))->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);

        $response->assertJsonPath('data.variance.items.0.cost.status', 'NO_EVIDENCE')
            ->assertJsonPath('data.variance.items.0.cost.planned', null)
            ->assertJsonPath('data.variance.items.0.cost.delta', null)
            ->assertJsonPath('data.variance.summary.cost_evidence_missing_count', 1);
        $this->assertEquals(0, (float) $response->json('data.variance.summary.cost_impact'));
    }

    public function test_a_cost_band_without_cost_evidence_is_skipped_not_measured_against_zero(): void
    {
        $this->activePolicy([['OVER', 'COST_AMOUNT', '0.5', null, 'BLOCKED']]);
        AvcoPool::query()->where('item_id', $this->pigment->id)->delete();

        $response = $this->actingAs($this->userWith('estimating.internal_cost.view'))->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]]);

        $response->assertJsonPath('data.variance.items.0.effect', 'AUDIT_ONLY')->assertJsonPath('data.variance.items.0.skipped.0.reason', 'There is no internal cost evidence for this item.');
    }

    public function test_the_inventory_proposal_plans_for_the_planned_quantity(): void
    {
        $this->stockPigment('a', '5');
        $user = $this->userWith('estimating.inventory_availability.view');

        $standard = $this->actingAs($user)->postJson($this->url(), $this->payload());
        $planned = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('6')]]);

        // 5 kg on hand covers the 4 kg standard, but not a 6 kg plan.
        $this->assertEquals(0, (float) $standard->json('data.inventory.items.0.shortage_quantity'));
        $this->assertEquals(4, (float) $planned->json('data.inventory.items.0.standard_quantity'));
        $this->assertEquals(6, (float) $planned->json('data.inventory.items.0.required_quantity'));
        $this->assertEquals(1, (float) $planned->json('data.inventory.items.0.shortage_quantity'));
    }

    public function test_adjustments_move_the_fingerprint_and_write_nothing(): void
    {
        $user = $this->userWith();
        $tables = ['material_variance_reasons', 'material_variance_policies', 'material_variance_policy_bands', 'stock_moves', 'assemblies'];
        $counts = fn (): array => array_map(fn (string $t): int => DB::table($t)->count(), $tables);
        $before = $counts();

        $plain = $this->actingAs($user)->postJson($this->url(), $this->payload())->json('data.evidence_fingerprint');
        $five = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('5')]])->json('data.evidence_fingerprint');
        $six = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['planned_adjustments' => [$this->adjust('6')]])->json('data.evidence_fingerprint');

        $this->assertCount(3, array_unique([$plain, $five, $six]));
        $this->assertSame($before, $counts());
    }

    public function test_a_stored_strategy_drives_the_proposal_and_says_which_level_supplied_it(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('a', '3');
        $this->stockPigment('b', '2');
        $actor = PermissionedActor::grant(User::factory()->create(), ['estimating.selection-policy.manage']);
        $set = app(SetMaterialSelectionPolicy::class);

        $set(MaterialSelectionScope::Company, null, MaterialSelectionStrategy::LowestInternalCost, 'Company prefers cost', $actor);
        $company = $this->actingAs($user)->postJson($this->url(), $this->payload());
        $company->assertJsonPath('data.inventory.strategy.code', 'LOWEST_INTERNAL_COST');
        $company->assertJsonPath('data.inventory.strategy.source', 'COMPANY');
        $company->assertJsonPath('data.inventory.items.0.strategy.source', 'COMPANY');

        $set(MaterialSelectionScope::Item, $this->pigment->id, MaterialSelectionStrategy::FewestPackages, 'Pigment is fiddly to open', $actor);
        $item = $this->actingAs($user)->postJson($this->url(), $this->payload());
        $item->assertJsonPath('data.inventory.items.0.strategy.code', 'FEWEST_PACKAGES');
        $item->assertJsonPath('data.inventory.items.0.strategy.source', 'ITEM');
        $item->assertJsonPath('data.inventory.items.0.recommendation.strategy.code', 'FEWEST_PACKAGES');
        $this->assertNotContains('FEWEST_PACKAGES', array_column($item->json('data.inventory.items.0.alternatives.*.strategy'), 'code'));

        $override = $this->actingAs($user)->postJson($this->url(), $this->payload() + ['selection_strategy' => 'MINIMISE_WASTE']);
        $override->assertJsonPath('data.inventory.items.0.strategy.source', 'REQUEST_OVERRIDE');
        $override->assertJsonPath('data.inventory.strategy.source', 'REQUEST_OVERRIDE');
    }

    public function test_changing_a_stored_strategy_changes_the_fingerprint_but_not_a_repeat_of_the_same_choice(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view');
        $this->stockPigment('a', '3');
        $actor = PermissionedActor::grant(User::factory()->create(), ['estimating.selection-policy.manage']);
        $set = app(SetMaterialSelectionPolicy::class);
        $print = fn () => $this->actingAs($user)->postJson($this->url(), $this->payload())->json('data.evidence_fingerprint');

        $before = $print();
        $this->assertSame($before, $print());
        $set(MaterialSelectionScope::Item, $this->pigment->id, MaterialSelectionStrategy::FewestPackages, 'Change one', $actor);
        $after = $print();
        $this->assertNotSame($before, $after);
        $set(MaterialSelectionScope::Item, $this->pigment->id, MaterialSelectionStrategy::FewestPackages, 'Same again', $actor);
        $this->assertSame($after, $print());
    }

    // ---- hand-built stock mix (ADR 0072) ----

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function mixPayload(array $lines): array
    {
        return $this->payload() + ['stock_mix' => $lines];
    }

    /** @return array<string, mixed> */
    private function draw(string $warehouseCode, string $quantity, ?Uom $uom = null, ?int $batchId = null): array
    {
        return [
            'item_id' => $this->pigment->id, 'location_id' => $this->location($warehouseCode)->id,
            'batch_id' => $batchId, 'quantity' => $quantity, 'uom_id' => ($uom ?? $this->kg)->id,
        ];
    }

    private function stockTwoWarehouses(): void
    {
        $this->stockPigment('a', '3');
        $this->stockPigment('b', '2');
    }

    public function test_a_complete_mix_reports_where_each_quantity_and_cost_came_from(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose', 'estimating.internal_cost.view');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '2'), $this->draw('b', '2')]));

        $response->assertOk();
        $mix = 'data.inventory.items.0.selected_mix';
        $response->assertJsonPath("{$mix}.is_complete", true);
        $response->assertJsonPath("{$mix}.required_quantity", '4.000000000');
        $response->assertJsonPath("{$mix}.covered_quantity", '4.0000');
        $response->assertJsonPath("{$mix}.shortage_quantity", '0.0000');
        $response->assertJsonPath("{$mix}.pick_count", 2);
        $response->assertJsonPath("{$mix}.lines.0.quantity", '2.000000000');
        $response->assertJsonPath("{$mix}.lines.0.usable_before_quantity", '3.0000');
        $response->assertJsonPath("{$mix}.lines.0.remaining_after_quantity", '1.000000000');
        $response->assertJsonPath("{$mix}.lines.1.remaining_after_quantity", '0.000000000');
        $response->assertJsonPath("{$mix}.lines.0.unit_cost", '3.0000');
        $response->assertJsonPath("{$mix}.lines.0.line_cost", '6.0000');
        $response->assertJsonPath("{$mix}.estimated_cost", '12.0000');
        $response->assertJsonPath("{$mix}.remaining_after_use_quantity", '1.0000');
        // The recommendation drew 3 + 1; this mix is different, and at one AVCO cost costs the same.
        $response->assertJsonPath("{$mix}.same_as_recommendation", false);
        $response->assertJsonPath("{$mix}.cost_difference", '0.0000');
        // The recommendation itself is untouched by the user's choice.
        $response->assertJsonPath('data.inventory.items.0.recommendation.covered_quantity', '4.000000000');
    }

    public function test_a_mix_that_covers_only_part_of_the_requirement_is_reported_as_a_shortage_not_hidden(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '1')]));

        $response->assertOk();
        $response->assertJsonPath('data.inventory.items.0.selected_mix.is_complete', false);
        $response->assertJsonPath('data.inventory.items.0.selected_mix.shortage_quantity', '3.0000');
        $this->assertStringContainsString('leaves 3 kg', implode(' ', $response->json('data.inventory.warnings')));
    }

    public function test_a_mix_that_exceeds_a_usable_balance_is_refused_and_says_how_much_is_usable(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '3.5')]));

        $response->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.quantity');
        $this->assertStringContainsString('Only 3 kg', $response->json('errors')['stock_mix.0.quantity'][0]);
    }

    public function test_a_mix_that_draws_more_than_the_plan_is_refused_so_it_cannot_bypass_the_variance_rules(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '3'), $this->draw('b', '2')]));

        $response->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.quantity');
        $this->assertStringContainsString('change the planned quantity', $response->json('errors')['stock_mix.0.quantity'][0]);
    }

    public function test_a_planned_adjustment_changes_what_a_mix_must_cover_and_the_variance_rules_still_apply(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($user)->postJson($this->url(), $this->payload() + [
            'planned_adjustments' => [$this->adjust('5')],
            'stock_mix' => [$this->draw('a', '3'), $this->draw('b', '2')],
        ]);

        $response->assertOk();
        // The mix covers the planned 5 kg exactly — and choosing it by hand did nothing to the variance verdict.
        $response->assertJsonPath('data.inventory.items.0.selected_mix.required_quantity', '5.000000000');
        $response->assertJsonPath('data.inventory.items.0.selected_mix.is_complete', true);
        $response->assertJsonPath('data.variance.items.0.effect', 'REASON_REQUIRED');
        $response->assertJsonPath('data.variance.items.0.can_adopt', false);
        $response->assertJsonPath('data.variance.summary.needs_reason_count', 1);
    }

    public function test_stock_that_cannot_be_drawn_is_refused_with_the_reason(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockPigment('a', '3');
        $this->stockPigment('q', '2', LocationType::Quarantine);
        $this->location('empty'); // a real location holding none of this item

        $quarantine = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('q', '1')]));
        $quarantine->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.location_id');
        $this->assertStringContainsString('does not count as usable', $quarantine->json('errors')['stock_mix.0.location_id'][0]);

        $none = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('empty', '1')]));
        $none->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.location_id');
        $this->assertStringContainsString('No stock of pigment is held', $none->json('errors')['stock_mix.0.location_id'][0]);
    }

    public function test_a_location_outside_the_narrowed_scope_is_refused(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('b', '1')]) + [
            'inventory_scope' => ['location_ids' => [$this->location('a')->id]],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.location_id');
    }

    public function test_the_same_stock_listed_twice_a_foreign_material_and_a_missing_line_field_are_refused(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '1'), $this->draw('a', '1')]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix.1.location_id');
        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([['item_id' => $this->paint->id] + $this->draw('a', '1')]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.item_id');
        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([['location_id' => $this->location('a')->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['stock_mix.0.item_id', 'stock_mix.0.quantity', 'stock_mix.0.uom_id']);
        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '0')]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.quantity');
    }

    public function test_quantities_may_be_entered_in_another_unit_and_a_part_of_a_pack_is_just_a_smaller_quantity(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();
        $gram = Uom::create(['code' => 'g', 'name' => 'Gram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '0.001', 'is_base' => false]);
        $drum = Uom::create(['code' => 'drum', 'name' => 'Drum', 'dimension' => UomDimension::Count, 'factor_to_base' => '1', 'is_base' => true]);
        ItemQuantityUnit::create(['item_id' => $this->pigment->id, 'uom_id' => $drum->id, 'factor' => '2']); // one drum holds 2 kg

        $response = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([
            $this->draw('a', '1500', $gram), // 1.5 kg
            $this->draw('b', '1.25', $drum), // 0.6 ... 1.25 drums = 2.5 kg is more than b holds; use 1 drum below
        ]));
        $response->assertUnprocessable()->assertJsonValidationErrors('stock_mix.1.quantity');

        $ok = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([
            $this->draw('a', '2000', $gram),  // 2 kg
            $this->draw('b', '1', $drum),     // 1 whole drum = 2 kg
        ]));
        $ok->assertOk();
        $ok->assertJsonPath('data.inventory.items.0.selected_mix.lines.0.quantity', '2.000000000');
        $ok->assertJsonPath('data.inventory.items.0.selected_mix.lines.0.entered_uom.code', 'g');
        $ok->assertJsonPath('data.inventory.items.0.selected_mix.lines.1.entered_quantity', '1');
        $ok->assertJsonPath('data.inventory.items.0.selected_mix.lines.1.stock_unit_factor', '2');

        // A fraction of a drum is a stock quantity like any other: 0.75 drum = 1.5 kg, leaving 0.5 kg at b.
        $part = $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '2.5'), $this->draw('b', '0.75', $drum)]));
        $part->assertOk();
        $part->assertJsonPath('data.inventory.items.0.selected_mix.lines.1.quantity', '1.500000000');
        $part->assertJsonPath('data.inventory.items.0.selected_mix.lines.1.remaining_after_quantity', '0.500000000');

        // A unit that does not convert to the item is refused.
        $sqm = $this->sqm;
        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '1', $sqm)]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.uom_id');
    }

    public function test_an_item_that_only_moves_in_whole_units_refuses_a_fractional_draw(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();
        $this->pigment->update(['quantity_precision' => '1']);

        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '2.5'), $this->draw('b', '1.5')]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix.0.quantity');
    }

    public function test_choosing_stock_needs_both_the_availability_and_the_choose_capability(): void
    {
        $this->stockTwoWarehouses();

        $this->actingAs($this->userWith())->postJson($this->url(), $this->mixPayload([$this->draw('a', '1')]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix');
        // Seeing availability is not enough: choosing by hand is its own capability (ADR 0076).
        $this->actingAs($this->userWith('estimating.inventory_availability.view'))->postJson($this->url(), $this->mixPayload([$this->draw('a', '1')]))
            ->assertUnprocessable()->assertJsonValidationErrors('stock_mix');
    }

    public function test_cost_of_a_mix_is_hidden_without_the_cost_capability_and_a_mix_moves_the_fingerprint(): void
    {
        $blind = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();

        $response = $this->actingAs($blind)->postJson($this->url(), $this->mixPayload([$this->draw('a', '2'), $this->draw('b', '2')]));
        $response->assertOk();
        $response->assertJsonPath('data.inventory.items.0.selected_mix.estimated_cost', null);
        $response->assertJsonPath('data.inventory.items.0.selected_mix.lines.0.unit_cost', null);
        $response->assertJsonPath('data.inventory.items.0.selected_mix.cost_difference', null);

        $plain = $this->actingAs($blind)->postJson($this->url(), $this->payload())->json('data.evidence_fingerprint');
        $this->assertNotSame($plain, $response->json('data.evidence_fingerprint'));
    }

    public function test_choosing_a_mix_reserves_and_posts_nothing(): void
    {
        $user = $this->userWith('estimating.inventory_availability.view', 'estimating.stock_mix.choose');
        $this->stockTwoWarehouses();
        $counts = fn (): array => array_map(
            fn (string $table): int => DB::table($table)->count(),
            ['stock_moves', 'stock_layers', 'avco_pools', 'stock_valuation_entries', 'negative_stock_shortages', 'stock_batches'],
        );
        $before = $counts();

        $this->actingAs($user)->postJson($this->url(), $this->mixPayload([$this->draw('a', '2'), $this->draw('b', '2')]))->assertOk();

        $this->assertSame($before, $counts());
    }

    private function url(): string
    {
        return "/api/v1/assemblies/{$this->assembly->id}/material-preview";
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function adjust(string $quantity, array $extra = []): array
    {
        return array_merge(['item_id' => $this->pigment->id, 'planned_quantity' => $quantity, 'uom_id' => $this->kg->id], $extra);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: Uom|null, 4: string}> $bands */
    private function activePolicy(array $bands): MaterialVariancePolicy
    {
        $policy = MaterialVariancePolicy::create([
            'scope_type' => 'COMPANY', 'reason' => 'Test', 'status' => 'ACTIVE', 'effective_from' => now()->subMonth()->toDateString(), 'changed_by' => User::factory()->create()->id,
        ]);
        foreach ($bands as [$direction, $measure, $threshold, $uom, $effect]) {
            $policy->bands()->create(['direction' => $direction, 'measure' => $measure, 'threshold' => $threshold, 'uom_id' => $uom?->id, 'effect' => $effect]);
        }

        return $policy;
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
        (new ActivateFormulationVersion(new FormulationCycleGuard))(
            $version,
            PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']),
        );

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

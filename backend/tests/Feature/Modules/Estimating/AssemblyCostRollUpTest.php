<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Actions\ActivateOverheadPolicy;
use App\Modules\Estimating\Actions\ExplodeAssemblyVersion;
use App\Modules\Estimating\Actions\ExplodeFormulationVersion;
use App\Modules\Estimating\Actions\ResolveOverheadPolicy;
use App\Modules\Estimating\Actions\ResolveResourceRate;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Estimating\Models\AssemblyVersion;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Models\ResourceRate;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\IngredientCostResolver;
use App\Modules\Estimating\Support\OverheadAmountCalculator;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Support\UomConversionService;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * Exercises docs/domain/assemblies.md#cost-roll-up end to end: the four
 * direct costs (never including overhead in their own base — see
 * ExplodeAssemblyVersion's docblock), the overhead resolver/calculator
 * wired in from Steps 1-2, and margin/markup — using ExplodeAssemblyVersion
 * directly (not HTTP) so every figure can be hand-verified exactly.
 */
class AssemblyCostRollUpTest extends TestCase
{
    use RefreshDatabase;

    private Uom $kg;

    private Uom $hour;

    private Uom $minute;

    private Uom $sqm;

    private Uom $litre;

    private Item $pigment;

    private Item $electrician;

    private Item $crane;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->hour = Uom::create(['code' => 'hr', 'name' => 'Hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '1', 'is_base' => true]);
        // A clean, exactly-representable fraction of an hour (unlike a real
        // minute, whose factor_to_base — 1/60 — cannot terminate within
        // uoms.factor_to_base's decimal(19,9)), so the converted result is
        // hand-verifiable to the last digit rather than merely "close".
        $this->minute = Uom::create(['code' => 'half-hr', 'name' => 'Half hour', 'dimension' => UomDimension::Time, 'factor_to_base' => '0.500000000']);
        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1', 'is_base' => true]);
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);

        $this->category = Category::create(['code' => 'RESOURCES', 'name' => 'Resources', 'default_valuation_method' => 'AVCO']);

        $this->pigment = Item::create(['code' => 'demo-pigment', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->category->id]);
        AvcoPool::create([
            'item_id' => $this->pigment->id, 'scope' => AvcoPoolScope::CompanyItem,
            'qty_on_hand' => '100', 'inventory_value' => '1000',
            'avg_unit_cost' => '10.0000', 'base_avg_unit_cost' => '10.0000',
            'last_valid_avg_unit_cost' => '10.0000', 'status' => AvcoPoolStatus::Active,
        ]);

        $this->electrician = Item::create(['code' => 'demo-electrician', 'name' => 'Electrician', 'type' => ItemType::Labour, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id]);
        ResourceRate::create(['item_id' => $this->electrician->id, 'rate' => '20.000000', 'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-01-01']);

        $this->crane = Item::create(['code' => 'demo-crane', 'name' => 'Crane', 'type' => ItemType::Plant, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id]);
        ResourceRate::create(['item_id' => $this->crane->id, 'rate' => '50.000000', 'rate_uom_id' => $this->hour->id, 'effective_from' => '2026-01-01']);
    }

    private function explode(): ExplodeAssemblyVersion
    {
        $uomConverter = new UomConversionService;
        $ingredientCost = new IngredientCostResolver;

        return new ExplodeAssemblyVersion(
            $ingredientCost,
            new ResolveResourceRate,
            new ResolveOverheadPolicy,
            new OverheadAmountCalculator,
            new ExplodeFormulationVersion($ingredientCost, $uomConverter),
            $uomConverter,
        );
    }

    private function assembly(string $marginType = 'MARKUP', string $marginPct = '20'): Assembly
    {
        return Assembly::create([
            'code' => 'ASM-'.str()->random(6), 'name' => 'Test Assembly', 'work_uom_id' => $this->sqm->id,
            'margin_type' => $marginType, 'margin_pct' => $marginPct,
        ]);
    }

    /** @param list<array<string, mixed>> $components */
    private function activatedVersion(Assembly $assembly, array $components): AssemblyVersion
    {
        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        foreach ($components as $sequence => $component) {
            $version->components()->create([...$component, 'sequence' => $sequence]);
        }
        (new ActivateAssemblyVersion)($version, PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']));

        return $version->refresh();
    }

    private function companyOverheadPolicy(string $driverType, string $rate): OverheadPolicy
    {
        $user = User::factory()->create();
        $policy = OverheadPolicy::create([
            'scope_type' => 'COMPANY', 'driver_type' => $driverType, 'rate' => $rate,
            'reason' => 'test fixture', 'changed_by' => $user->id,
        ])->refresh();
        (new ActivateOverheadPolicy)($policy, PermissionedActor::grant($user, ['estimating.overhead-policy.manage']));

        return $policy->refresh();
    }

    public function test_the_full_five_part_breakdown_matches_hand_computed_figures(): void
    {
        $assembly = $this->assembly('MARKUP', '20');
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $this->kg->id, 'wastage_pct' => '5.0000', 'multiplier' => '2.0000'],
            ['kind' => 'LABOUR', 'item_id' => $this->electrician->id, 'qty_per_work_unit' => '1.500000', 'uom_id' => $this->hour->id],
            ['kind' => 'PLANT', 'item_id' => $this->crane->id, 'qty_per_work_unit' => '0.500000', 'uom_id' => $this->hour->id],
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '15.0000'],
        ]);
        $this->companyOverheadPolicy('PCT_DIRECT_COST', '10');

        $breakdown = ($this->explode())($version);

        // material: 0.5 * 1.05 * 2 = 1.05kg * $10 = $10.50
        $this->assertSame('10.5000', (string) $breakdown->materialCost);
        // labour: 1.5hr * $20 = $30.00
        $this->assertSame('30.0000', (string) $breakdown->labourCost);
        $this->assertSame('1.500000000', (string) $breakdown->labourHours);
        // plant: 0.5hr * $50 = $25.00
        $this->assertSame('25.0000', (string) $breakdown->plantCost);
        $this->assertSame('15.0000', (string) $breakdown->subcontractCost);
        // direct: 10.50 + 30 + 25 + 15 = 80.50
        $this->assertSame('80.5000', (string) $breakdown->directCost);
        // overhead: 10% of 80.50 = 8.05
        $this->assertSame('8.0500', (string) $breakdown->overheadAmount);
        $this->assertSame('COMPANY', $breakdown->overheadMethod->value);
        // cost/unit: 80.50 + 8.05 = 88.55
        $this->assertSame('88.5500', (string) $breakdown->costPerUnit);
        // sell/unit (MARKUP 20%): 88.55 * 1.20 = 106.26
        $this->assertSame('106.2600', (string) $breakdown->sellPerUnit);

        $sources = collect($breakdown->components)->map(fn ($c) => $c->costSource)->all();
        $this->assertSame(['AVCO stock valuation', 'Resource rate as of 2026-01-01', 'Resource rate as of 2026-01-01', 'Direct rate'], $sources);
    }

    public function test_labour_qty_in_a_different_time_unit_converts_to_hours_for_the_per_labour_hour_basis(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            // 3 half-hour blocks = 1.5 hours.
            ['kind' => 'LABOUR', 'item_id' => $this->electrician->id, 'qty_per_work_unit' => '3.000000', 'uom_id' => $this->minute->id],
        ]);

        $breakdown = ($this->explode())($version);

        $this->assertSame('1.500000000', (string) $breakdown->labourHours);
    }

    public function test_per_labour_hour_driver_uses_total_labour_hours_as_its_basis(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'LABOUR', 'item_id' => $this->electrician->id, 'qty_per_work_unit' => '2.000000', 'uom_id' => $this->hour->id],
        ]);
        $this->companyOverheadPolicy('PER_LABOUR_HOUR', '4');

        $breakdown = ($this->explode())($version);

        // 2 hours labour, $4/hr overhead => $8.00
        $this->assertSame('8.0000', (string) $breakdown->overheadAmount);
    }

    public function test_per_work_unit_driver_uses_exactly_one_work_unit_as_its_basis(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '1.0000'],
        ]);
        $this->companyOverheadPolicy('PER_WORK_UNIT', '8');

        $breakdown = ($this->explode())($version);

        $this->assertSame('8.0000', (string) $breakdown->overheadAmount);
    }

    public function test_margin_and_markup_produce_the_documented_different_numbers(): void
    {
        // docs/domain/assemblies.md "Margin is not markup": cost 100, 20% ->
        // MARKUP 120, MARGIN 125.
        $markupAssembly = $this->assembly('MARKUP', '20');
        $markupVersion = $this->activatedVersion($markupAssembly, [
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ]);
        $marginAssembly = $this->assembly('MARGIN', '20');
        $marginVersion = $this->activatedVersion($marginAssembly, [
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ]);

        $explode = $this->explode();

        $this->assertSame('120.0000', (string) $explode($markupVersion)->sellPerUnit);
        $this->assertSame('125.0000', (string) $explode($marginVersion)->sellPerUnit);
    }

    public function test_margin_at_or_above_100_percent_fails_loudly_instead_of_dividing_by_zero(): void
    {
        $assembly = $this->assembly('MARGIN', '100');
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '10.0000'],
        ]);

        $this->expectException(DomainInvariantException::class);
        ($this->explode())($version);
    }

    public function test_a_nested_formulation_material_component_prices_from_its_active_version(): void
    {
        $tintedBase = Item::create(['code' => 'demo-tinted-base', 'name' => 'Tinted Base', 'type' => ItemType::SemiFinished, 'stock_uom_id' => $this->litre->id, 'category_id' => $this->category->id]);
        $formulation = Formulation::create([
            'output_item_id' => $tintedBase->id, 'code' => 'F-TINTED', 'name' => 'Tinted Base',
            'output_qty' => '1', 'output_uom_id' => $this->litre->id,
        ]);
        $formulationVersion = $formulation->versions()->create(['version_no' => 1])->refresh();
        $formulationVersion->lines()->create(['input_item_id' => $this->pigment->id, 'qty' => '0.750000', 'uom_id' => $this->kg->id, 'sequence' => 0]);
        (new ActivateFormulationVersion(new FormulationCycleGuard))(
            $formulationVersion,
            PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']),
        );
        // cost_per_output_unit = 0.75kg * $10/kg = $7.50/L

        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            [
                'kind' => 'MATERIAL', 'item_id' => $tintedBase->id, 'formulation_id' => $formulation->id,
                'qty_per_work_unit' => '2.000000', 'uom_id' => $this->litre->id,
            ],
        ]);

        $breakdown = ($this->explode())($version);

        // 2L * $7.50/L = $15.00
        $this->assertSame('15.0000', (string) $breakdown->materialCost);
        $this->assertStringContainsString('Formulation F-TINTED', $breakdown->components[0]->costSource);
    }

    public function test_an_application_rate_material_component_resolves_qty_and_item_from_the_rate(): void
    {
        $rate = ApplicationRate::create([
            'item_id' => $this->pigment->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => '0.400000', 'uom_id' => $this->kg->id, 'is_default' => true,
        ]);

        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            [
                'kind' => 'MATERIAL', 'application_rate_id' => $rate->id,
                'qty_per_work_unit' => '999.000000', 'uom_id' => $this->kg->id,
            ],
        ]);

        $breakdown = ($this->explode())($version);

        // 0.4kg (from the rate, NOT the component's own 999 placeholder) * $10 = $4.00
        $this->assertSame('4.0000', (string) $breakdown->materialCost);
    }

    public function test_rate_override_overrides_the_resolved_material_cost(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            [
                'kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '1.000000',
                'uom_id' => $this->kg->id, 'rate_override' => '99.0000',
            ],
        ]);

        $breakdown = ($this->explode())($version);

        $this->assertSame('99.0000', (string) $breakdown->materialCost);
        $this->assertSame('Manual override', $breakdown->components[0]->costSource);
    }

    public function test_a_labour_item_with_no_resource_rate_configured_costs_zero_not_an_error(): void
    {
        $unratedElectrician = Item::create(['code' => 'demo-unrated', 'name' => 'Unrated Labour', 'type' => ItemType::Labour, 'stock_uom_id' => $this->hour->id, 'category_id' => $this->category->id]);
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'LABOUR', 'item_id' => $unratedElectrician->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->hour->id],
        ]);

        $breakdown = ($this->explode())($version);

        $this->assertSame('0.0000', (string) $breakdown->labourCost);
        $this->assertSame('No resource rate configured', $breakdown->components[0]->costSource);
    }

    public function test_an_incompatible_uom_between_a_labour_component_and_its_resolved_resource_rate_fails_loudly(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            // The electrician's resource rate is priced per hour (TIME); costing
            // this component in kg (MASS) can never convert.
            ['kind' => 'LABOUR', 'item_id' => $this->electrician->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->kg->id],
        ]);

        $this->expectException(DomainInvariantException::class);
        ($this->explode())($version);
    }

    public function test_overhead_resolution_prefers_assembly_scope_over_company_default(): void
    {
        $this->companyOverheadPolicy('PCT_DIRECT_COST', '5');
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ]);

        $user = User::factory()->create();
        $assemblyPolicy = OverheadPolicy::create([
            'scope_type' => 'ASSEMBLY', 'scope_id' => $assembly->id, 'driver_type' => 'PCT_DIRECT_COST',
            'rate' => '25', 'reason' => 'exception', 'changed_by' => $user->id,
        ])->refresh();
        (new ActivateOverheadPolicy)($assemblyPolicy, PermissionedActor::grant($user, ['estimating.overhead-policy.manage']));

        $breakdown = ($this->explode())($version->refresh());

        $this->assertSame('ASSEMBLY', $breakdown->overheadMethod->value);
        $this->assertSame('25.0000', (string) $breakdown->overheadAmount);
    }

    public function test_no_overhead_policy_anywhere_costs_zero_overhead_not_an_error(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $this->sqm->id, 'rate_override' => '100.0000'],
        ]);

        $breakdown = ($this->explode())($version);

        $this->assertSame('NONE', $breakdown->overheadMethod->value);
        $this->assertNull($breakdown->overheadPolicy);
        $this->assertSame('0.0000', (string) $breakdown->overheadAmount);
        $this->assertSame('100.0000', (string) $breakdown->costPerUnit);
    }

    public function test_the_cost_breakdown_api_response_is_fully_explainable(): void
    {
        $assembly = $this->assembly('MARKUP', '10');
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->kg->id],
        ]);
        $this->companyOverheadPolicy('PCT_MATERIAL', '10');

        $user = PermissionedActor::grant(User::factory()->create(), ['estimating.internal_cost.view']);
        $response = $this->actingAs($user)->getJson("/api/v1/assembly-versions/{$version->id}/cost-breakdown");

        $response->assertOk();
        $response->assertJsonPath('data.version_id', $version->id);
        $response->assertJsonPath('data.overhead.method', 'COMPANY');
        $response->assertJsonPath('data.overhead.driver_type', 'PCT_MATERIAL');
        $response->assertJsonPath('data.overhead.rate', '10.000000');
        $response->assertJsonPath('data.overhead.amount', '1.0000');
        $response->assertJsonPath('data.material_cost', '10.0000');
        $response->assertJsonPath('data.cost_per_unit', '11.0000');
        $response->assertJsonPath('data.sell_per_unit', '12.1000');
        $response->assertJsonPath('data.components.0.cost_source', 'AVCO stock valuation');
        $response->assertJsonPath('data.components.0.resolved_unit_cost', '10.0000');
    }

    public function test_assembly_manager_without_internal_cost_cannot_read_cost_breakdown(): void
    {
        $assembly = $this->assembly();
        $version = $this->activatedVersion($assembly, [
            ['kind' => 'MATERIAL', 'item_id' => $this->pigment->id, 'qty_per_work_unit' => '1.000000', 'uom_id' => $this->kg->id],
        ]);
        $manager = PermissionedActor::grant(User::factory()->create(), ['estimating.assembly.manage']);

        $this->actingAs($manager)->getJson("/api/v1/assembly-versions/{$version->id}/cost-breakdown")
            ->assertForbidden()->assertDontSee('cost_per_unit');
    }
}

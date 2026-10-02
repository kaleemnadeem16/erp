<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Actions\ExplodeFormulationVersion;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Estimating\Support\FormulationExplosion;
use App\Modules\Estimating\Support\FormulationLineBreakdown;
use App\Modules\Estimating\Support\IngredientCostResolver;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Support\UomConversionService;
use App\Support\UomDimension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PermissionedActor;
use Tests\TestCase;

/**
 * Exercises docs/domain/formulations.md#cost-of-one-output-unit against a
 * two-level worked example: Shade (FINISHED) consumes Pigment directly and
 * Tinted Base (SEMI_FINISHED, itself formulated from Pigment + Binder) —
 * the same nesting shape as that doc's own diagram.
 */
class FormulationExplosionTest extends TestCase
{
    use RefreshDatabase;

    private Uom $kg;

    private Uom $litre;

    private Uom $millilitre;

    private Item $pigment;

    private Item $binder;

    private Item $tintedBase;

    private Item $shade;

    private User $formulationActor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formulationActor = PermissionedActor::grant(User::factory()->create(), ['estimating.formulation.manage']);

        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1']);
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1']);
        $this->millilitre = Uom::create(['code' => 'mL', 'name' => 'Millilitre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '0.001']);

        $category = Category::create(['code' => 'PAINT', 'name' => 'Paint', 'default_valuation_method' => 'AVCO']);

        $this->pigment = Item::create(['code' => 'PIGMENT', 'name' => 'Pigment', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $category->id]);
        $this->binder = Item::create(['code' => 'BINDER', 'name' => 'Binder', 'type' => ItemType::Raw, 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
        $this->tintedBase = Item::create(['code' => 'TINTED-BASE', 'name' => 'Tinted Base', 'type' => ItemType::SemiFinished, 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);
        $this->shade = Item::create(['code' => 'SHADE', 'name' => 'Shade #4471', 'type' => ItemType::Finished, 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);

        $this->pool($this->pigment, '10.0000');
        $this->pool($this->binder, '5.0000');
    }

    private function pool(Item $item, string $avgUnitCost): void
    {
        AvcoPool::create([
            'item_id' => $item->id, 'scope' => AvcoPoolScope::CompanyItem,
            'qty_on_hand' => '100', 'inventory_value' => '1000',
            'avg_unit_cost' => $avgUnitCost, 'base_avg_unit_cost' => $avgUnitCost,
            'last_valid_avg_unit_cost' => $avgUnitCost, 'status' => AvcoPoolStatus::Active,
        ]);
    }

    /** @param list<array<string, mixed>> $lines */
    private function activatedFormulation(Item $output, array $lines, string $outputQty = '1'): Formulation
    {
        $formulation = Formulation::create([
            'output_item_id' => $output->id, 'code' => 'F-'.$output->code, 'name' => $output->name,
            'output_qty' => $outputQty, 'output_uom_id' => $this->litre->id,
        ]);
        $version = $formulation->versions()->create(['version_no' => 1])->refresh();
        foreach ($lines as $sequence => $line) {
            $version->lines()->create([...$line, 'sequence' => $sequence]);
        }
        (new ActivateFormulationVersion(new FormulationCycleGuard))($version, $this->formulationActor);

        return $formulation->refresh();
    }

    public function test_a_simple_formulation_prices_correctly_with_wastage(): void
    {
        // 0.5 kg pigment at 10% wastage + 0.6 L binder, no wastage, per litre.
        $formulation = $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $this->pigment->id, 'qty' => '0.500000', 'uom_id' => $this->kg->id, 'wastage_pct' => '10.0000'],
            ['input_item_id' => $this->binder->id, 'qty' => '0.600000', 'uom_id' => $this->litre->id],
        ]);

        $explosion = $this->explode($formulation);

        // (0.5 * 1.10 * 10.00) + (0.6 * 1.00 * 5.00) = 5.50 + 3.00 = 8.50
        $this->assertSame('8.5000', (string) $explosion->costPerOutputUnit);
        $this->assertSame('8.5000', (string) $explosion->totalCost);
    }

    public function test_a_line_in_a_different_uom_converts_before_costing(): void
    {
        // Same as above but binder specified in millilitres.
        $formulation = $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $this->pigment->id, 'qty' => '0.500000', 'uom_id' => $this->kg->id, 'wastage_pct' => '10.0000'],
            ['input_item_id' => $this->binder->id, 'qty' => '600.000000', 'uom_id' => $this->millilitre->id],
        ]);

        $explosion = $this->explode($formulation);

        $this->assertSame('8.5000', (string) $explosion->costPerOutputUnit);
    }

    public function test_nested_formulation_cost_compounds_through_wastage(): void
    {
        $tintedBaseFormulation = $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $this->pigment->id, 'qty' => '0.500000', 'uom_id' => $this->kg->id, 'wastage_pct' => '10.0000'],
            ['input_item_id' => $this->binder->id, 'qty' => '0.600000', 'uom_id' => $this->litre->id],
        ]);
        // Tinted Base costs 8.50/L (previous test).

        // Shade: 0.2 L tinted base (no extra wastage) + 0.1 kg pigment direct at 5% wastage.
        $shadeFormulation = $this->activatedFormulation($this->shade, [
            ['input_item_id' => $this->tintedBase->id, 'qty' => '0.200000', 'uom_id' => $this->litre->id],
            ['input_item_id' => $this->pigment->id, 'qty' => '0.100000', 'uom_id' => $this->kg->id, 'wastage_pct' => '5.0000'],
        ]);

        $explosion = $this->explode($shadeFormulation);

        // (0.2 * 1.00 * 8.50) + (0.1 * 1.05 * 10.00) = 1.70 + 1.05 = 2.75
        $this->assertSame('2.7500', (string) $explosion->costPerOutputUnit);
        $this->assertCount(2, $explosion->lines);

        $tintedBaseLine = collect($explosion->lines)->first(fn (FormulationLineBreakdown $l) => $l->line->input_item_id === $this->tintedBase->id);
        $this->assertNotNull($tintedBaseLine->subExplosion);
        $this->assertSame($tintedBaseFormulation->activeVersion->id, $tintedBaseLine->subExplosion->version->id);
        $this->assertSame('8.5000', (string) $tintedBaseLine->subExplosion->costPerOutputUnit);

        $pigmentLine = collect($explosion->lines)->first(fn (FormulationLineBreakdown $l) => $l->line->input_item_id === $this->pigment->id);
        $this->assertNull($pigmentLine->subExplosion);
    }

    public function test_an_item_never_received_costs_zero(): void
    {
        $neverPurchased = Item::create(['code' => 'NEVER', 'name' => 'Never Purchased', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->pigment->category_id]);
        $formulation = $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $neverPurchased->id, 'qty' => '1.000000', 'uom_id' => $this->kg->id],
        ]);

        $explosion = $this->explode($formulation);

        $this->assertSame('0.0000', (string) $explosion->costPerOutputUnit);
    }

    public function test_fifo_valued_ingredients_are_not_yet_supported(): void
    {
        $fifoItem = Item::create(['code' => 'FIFO-ITEM', 'name' => 'FIFO Item', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->pigment->category_id, 'valuation_method' => ValuationMethod::Fifo]);
        $formulation = $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $fifoItem->id, 'qty' => '1.000000', 'uom_id' => $this->kg->id],
        ]);

        $this->expectException(DomainInvariantException::class);

        $this->explode($formulation);
    }

    public function test_a_warehouse_scoped_avco_pool_is_not_yet_supported(): void
    {
        $warehouse = Warehouse::create(['code' => 'WH1', 'name' => 'Warehouse 1', 'is_default' => true, 'active' => true]);
        $item = Item::create(['code' => 'WH-SCOPED', 'name' => 'Warehouse Scoped', 'type' => ItemType::Raw, 'stock_uom_id' => $this->kg->id, 'category_id' => $this->pigment->category_id, 'avco_pool_scope' => AvcoPoolScope::WarehouseItem]);
        AvcoPool::create([
            'item_id' => $item->id, 'scope' => AvcoPoolScope::WarehouseItem, 'warehouse_id' => $warehouse->id,
            'qty_on_hand' => '10', 'inventory_value' => '100', 'avg_unit_cost' => '10',
            'base_avg_unit_cost' => '10', 'last_valid_avg_unit_cost' => '10', 'status' => AvcoPoolStatus::Active,
        ]);
        $formulation = $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $item->id, 'qty' => '1.000000', 'uom_id' => $this->kg->id],
        ]);

        $this->expectException(DomainInvariantException::class);

        $this->explode($formulation);
    }

    public function test_direct_self_consumption_is_rejected(): void
    {
        $this->expectException(DomainInvariantException::class);

        $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $this->tintedBase->id, 'qty' => '1.000000', 'uom_id' => $this->litre->id],
        ]);
    }

    public function test_a_transitive_cycle_between_two_active_formulations_is_rejected(): void
    {
        // Tinted Base's formulation consumes Shade (SEMI_FINISHED for this test).
        $shadeAsSemiFinished = Item::create(['code' => 'SHADE-SF', 'name' => 'Shade (semi-finished)', 'type' => ItemType::SemiFinished, 'stock_uom_id' => $this->litre->id, 'category_id' => $this->pigment->category_id]);

        $this->activatedFormulation($this->tintedBase, [
            ['input_item_id' => $shadeAsSemiFinished->id, 'qty' => '1.000000', 'uom_id' => $this->litre->id],
        ]);

        // Now Shade's formulation tries to consume Tinted Base -> cycle.
        $this->expectException(DomainInvariantException::class);

        $this->activatedFormulation($shadeAsSemiFinished, [
            ['input_item_id' => $this->tintedBase->id, 'qty' => '1.000000', 'uom_id' => $this->litre->id],
        ]);
    }

    public function test_two_independently_built_draft_formulations_cannot_both_activate_into_a_live_cycle(): void
    {
        $shadeAsSemiFinished = Item::create(['code' => 'SHADE-SF2', 'name' => 'Shade (semi-finished) 2', 'type' => ItemType::SemiFinished, 'stock_uom_id' => $this->litre->id, 'category_id' => $this->pigment->category_id]);

        // Build both as DRAFT first — neither is active yet, so create-time
        // guards see no live cycle.
        $tintedBaseFormulation = Formulation::create(['output_item_id' => $this->tintedBase->id, 'code' => 'F-TB2', 'name' => 'Tinted Base 2', 'output_uom_id' => $this->litre->id]);
        $tintedBaseVersion = $tintedBaseFormulation->versions()->create(['version_no' => 1])->refresh();
        $tintedBaseVersion->lines()->create(['input_item_id' => $shadeAsSemiFinished->id, 'qty' => '1', 'uom_id' => $this->litre->id, 'sequence' => 0]);

        $shadeFormulation = Formulation::create(['output_item_id' => $shadeAsSemiFinished->id, 'code' => 'F-SHADE2', 'name' => 'Shade 2', 'output_uom_id' => $this->litre->id]);
        $shadeVersion = $shadeFormulation->versions()->create(['version_no' => 1])->refresh();
        $shadeVersion->lines()->create(['input_item_id' => $this->tintedBase->id, 'qty' => '1', 'uom_id' => $this->litre->id, 'sequence' => 0]);

        (new ActivateFormulationVersion(new FormulationCycleGuard))($tintedBaseVersion, $this->formulationActor);

        $this->expectException(DomainInvariantException::class);

        (new ActivateFormulationVersion(new FormulationCycleGuard))($shadeVersion, $this->formulationActor);
    }

    private function explode(Formulation $formulation): FormulationExplosion
    {
        return (new ExplodeFormulationVersion(
            new IngredientCostResolver,
            new UomConversionService,
        ))($formulation->activeVersion);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Modules\Estimating\Actions\ActivateFormulationVersion;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Estimating\Support\FormulationCycleGuard;
use App\Modules\Inventory\Models\AvcoPool;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\AvcoPoolScope;
use App\Modules\Inventory\Support\AvcoPoolStatus;
use App\Modules\Inventory\Support\ItemType;
use App\Modules\Inventory\Support\ValuationMethod;
use App\Modules\Parties\Models\CustomerAccount;
use App\Support\Status;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Two-level nesting over InventoryDemoSeeder's demo-paint-white, plus a
 * customer override and a superseded version, so the formulation screens
 * demonstrate every meaningful behaviour without any manual setup:
 *
 *   - demo-tinted-base (new SEMI_FINISHED) has its own formulation
 *     (pigment + a new demo-formulation-binder), ACTIVE.
 *   - demo-paint-white's company-default formulation consumes tinted base
 *     (nested — its cost comes from that formulation, not from stock) and
 *     pigment directly. Two versions: v1 (SUPERSEDED) and v2 (ACTIVE), so
 *     the version-history UI has something to show.
 *   - demo-paint-white also has a customer-specific formulation for
 *     Alpha Decorators (PartiesDemoSeeder's cus-alpha) with a richer mix —
 *     ResolveFormulation picks this one over the company default for that
 *     customer.
 *
 * demo-pig-blue (InventoryDemoSeeder) is COMPANY_ITEM-scoped AVCO, so it
 * already has one unambiguous cost — see IngredientCostResolver. No other
 * existing demo item qualifies (demo-pig-red is batch-scoped, demo-thinner
 * is FIFO), so this seeder adds the one new ingredient it needs itself
 * rather than reach into InventoryDemoSeeder for something that isn't
 * there.
 */
final class FormulationsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $kg = Uom::query()->where('code', 'kg')->firstOrFail();
        $litre = Uom::query()->where('code', 'l')->firstOrFail();
        $each = Uom::query()->where('code', 'ea')->firstOrFail();

        $pigment = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $paintWhite = Item::query()->where('code', 'demo-paint-white')->firstOrFail();

        $binder = Item::withTrashed()->updateOrCreate(
            ['code' => 'demo-formulation-binder'],
            [
                'name' => 'Acrylic Binder', 'description' => 'Binder used in formulation demo recipes.',
                'type' => ItemType::Raw, 'stock_uom_id' => $litre->id, 'is_stocked' => true,
                'is_purchasable' => true, 'is_sellable' => false, 'valuation_method' => ValuationMethod::Avco,
                'avco_pool_scope' => AvcoPoolScope::CompanyItem, 'category_id' => $pigment->category_id,
                'custom_fields' => [], 'status' => Status::Active,
            ],
        );
        $binder->restore();
        $this->pool($pigment, '18.0000');
        $this->pool($binder, '6.0000');

        $tintedBase = Item::withTrashed()->updateOrCreate(
            ['code' => 'demo-tinted-base'],
            [
                'name' => 'Tinted Base',
                'description' => 'Demo semi-finished base: mixed separately, then consumed by the White Enamel Paint formulations to demonstrate nested costing.',
                'type' => ItemType::SemiFinished, 'stock_uom_id' => $litre->id, 'is_stocked' => true,
                'is_purchasable' => false, 'is_sellable' => false, 'valuation_method' => ValuationMethod::Avco,
                'avco_pool_scope' => AvcoPoolScope::CompanyItem, 'category_id' => $pigment->category_id,
                'custom_fields' => [], 'status' => Status::Active,
            ],
        );
        $tintedBase->restore();

        // Tinted Base's own formulation: 0.4 kg pigment (5% wastage) + 0.6 L binder, per litre.
        $this->replaceFormulation(
            $tintedBase, 'demo-f-tinted-base', 'Tinted Base', $litre, null,
            [
                ['input_item_id' => $pigment->id, 'qty' => '0.400000', 'uom_id' => $kg->id, 'wastage_pct' => '5.0000'],
                ['input_item_id' => $binder->id, 'qty' => '0.600000', 'uom_id' => $litre->id],
            ],
        );

        // White Enamel Paint's company default: v1 then a revised v2 (superseding v1),
        // 0.5 L tinted base (nested) + 0.05 kg pigment direct per can.
        $defaultFormulation = $this->replaceFormulation(
            $paintWhite, 'demo-f-paint-white', 'White Enamel Paint — default', $each, null,
            [['input_item_id' => $tintedBase->id, 'qty' => '0.400000', 'uom_id' => $litre->id]],
        );
        $this->reviseFormulation($defaultFormulation, [
            ['input_item_id' => $tintedBase->id, 'qty' => '0.500000', 'uom_id' => $litre->id],
            ['input_item_id' => $pigment->id, 'qty' => '0.050000', 'uom_id' => $kg->id, 'wastage_pct' => '5.0000'],
        ]);

        // A customer override with a richer, more pigment-heavy mix.
        $alpha = CustomerAccount::query()->where('code', 'cus-alpha')->first();
        if ($alpha !== null) {
            $this->replaceFormulation(
                $paintWhite, 'demo-f-paint-white-alpha', 'White Enamel Paint — Alpha Decorators', $each, $alpha->id,
                [
                    ['input_item_id' => $tintedBase->id, 'qty' => '0.500000', 'uom_id' => $litre->id],
                    ['input_item_id' => $pigment->id, 'qty' => '0.120000', 'uom_id' => $kg->id, 'wastage_pct' => '5.0000'],
                ],
            );
        }
    }

    private function pool(Item $item, string $avgUnitCost): void
    {
        AvcoPool::query()->updateOrCreate(
            ['item_id' => $item->id, 'scope' => AvcoPoolScope::CompanyItem],
            [
                'qty_on_hand' => '500', 'inventory_value' => bcmul($avgUnitCost, '500', 4),
                'avg_unit_cost' => $avgUnitCost, 'base_avg_unit_cost' => $avgUnitCost,
                'last_valid_avg_unit_cost' => $avgUnitCost, 'status' => AvcoPoolStatus::Active,
            ],
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function replaceFormulation(Item $output, string $code, string $name, Uom $outputUom, ?int $customerId, array $lines): Formulation
    {
        $existing = Formulation::query()->where('code', $code)->first();
        $existing?->versions()->delete();
        $existing?->delete();

        $formulation = Formulation::create([
            'output_item_id' => $output->id, 'code' => $code, 'name' => $name,
            'output_uom_id' => $outputUom->id, 'customer_id' => $customerId,
        ])->refresh();

        $version = $formulation->versions()->create(['version_no' => 1])->refresh();
        foreach ($lines as $sequence => $line) {
            $version->lines()->create([...$line, 'sequence' => $sequence]);
        }
        (new ActivateFormulationVersion(new FormulationCycleGuard))($version);

        return $formulation->refresh();
    }

    /** @param list<array<string, mixed>> $lines */
    private function reviseFormulation(Formulation $formulation, array $lines): void
    {
        $nextVersionNo = (int) $formulation->versions()->max('version_no') + 1;
        $version = $formulation->versions()->create(['version_no' => $nextVersionNo])->refresh();
        foreach ($lines as $sequence => $line) {
            $version->lines()->create([...$line, 'sequence' => $sequence]);
        }
        (new ActivateFormulationVersion(new FormulationCycleGuard))($version);
    }
}

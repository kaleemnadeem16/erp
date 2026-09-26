<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Modules\Estimating\Actions\ActivateAssemblyVersion;
use App\Modules\Estimating\Models\Assembly;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Step 3's demo scenarios — see docs/domain/assemblies.md#cost-roll-up.
 * Both AssembliesDemoSeeder rows get a real ACTIVE version so the cost
 * breakdown, overhead resolution and margin/markup all have something to
 * show without any manual setup:
 *
 *   - demo-asm-wall-paint (grouped, MARGIN 30%) exercises MATERIAL (direct
 *     AVCO, demo-pig-blue, with wastage), LABOUR and PLANT priced from
 *     ResourceRatesDemoSeeder's rates, and a manual SUBCONTRACT rate — the
 *     "paint contractor: all layers" row in assemblies.md's optional-layers
 *     table. It resolves overhead through its assembly_group
 *     (OverheadPoliciesDemoSeeder's Labour & Installation override), not
 *     the company default. Deliberately does not reference
 *     demo-f-tinted-base (FormulationsDemoSeeder) for its own MATERIAL
 *     pricing — that formulation's own delete-and-recreate idempotency
 *     (replaceFormulation) would conflict with a real assembly_components
 *     FK on every re-seed; MATERIAL-via-formulation_id is already covered
 *     by AssemblyCostRollUpTest, so demo data doesn't need to duplicate it.
 *   - demo-asm-consult (ungrouped, MARKUP 25%) is LABOUR-only — the
 *     "consultancy" row — and resolves straight through to the company
 *     default overhead policy, the small-company case ADR 0049 requires to
 *     still work correctly.
 */
final class AssemblyVersionsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $sqm = Uom::query()->where('code', 'sqm')->firstOrFail();
        $kg = Uom::query()->where('code', 'kg')->firstOrFail();
        $hour = Uom::query()->where('code', 'hr')->firstOrFail();

        $pigment = Item::query()->where('code', 'demo-pig-blue')->firstOrFail();
        $painter = Item::query()->where('code', 'demo-painter-hour')->firstOrFail();
        $scaffold = Item::query()->where('code', 'demo-plant-scaffold')->firstOrFail();

        $this->ensureActiveVersion(
            Assembly::query()->where('code', 'demo-asm-wall-paint')->firstOrFail(),
            [
                [
                    'kind' => 'MATERIAL', 'item_id' => $pigment->id,
                    'qty_per_work_unit' => '0.120000', 'uom_id' => $kg->id, 'wastage_pct' => '5.0000', 'multiplier' => '2.0000',
                ],
                [
                    'kind' => 'LABOUR', 'item_id' => $painter->id,
                    'qty_per_work_unit' => '0.250000', 'uom_id' => $hour->id,
                ],
                [
                    'kind' => 'PLANT', 'item_id' => $scaffold->id,
                    'qty_per_work_unit' => '0.100000', 'uom_id' => $hour->id,
                ],
                [
                    'kind' => 'SUBCONTRACT', 'qty_per_work_unit' => '1', 'uom_id' => $sqm->id,
                    'rate_override' => '2.0000',
                ],
            ],
        );

        $this->ensureActiveVersion(
            Assembly::query()->where('code', 'demo-asm-consult')->firstOrFail(),
            [
                [
                    'kind' => 'LABOUR', 'item_id' => $painter->id,
                    'qty_per_work_unit' => '1.000000', 'uom_id' => $hour->id,
                ],
            ],
        );
    }

    /** @param list<array<string, mixed>> $components */
    private function ensureActiveVersion(Assembly $assembly, array $components): void
    {
        if ($assembly->activeVersion()->exists()) {
            return;
        }

        $version = $assembly->versions()->create(['version_no' => 1])->refresh();
        foreach ($components as $sequence => $component) {
            $version->components()->create([...$component, 'sequence' => $sequence]);
        }
        (new ActivateAssemblyVersion)($version);
    }
}

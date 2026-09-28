<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Demo pack conversions (ADR 0050, `item_quantity_units`): "1 pack unit =
 * factor × this item's own stock unit". Each row below is one deliberate
 * scenario for a distinct decision point of the conversion lookup — the
 * order a purchase order line resolves a unit in (the Pack Conversions
 * screen only maintains the rows):
 *
 *   1. this item's own pack conversion, if one exists (it may cross UoM dimension);
 *   2. otherwise the global, same-dimension UoM conversion;
 *   3. otherwise the line is rejected — no silent guess.
 *
 * Scenarios (item → pack unit = quantity of its stock unit):
 *
 *   A. Same unit, different quantity per item — the reason the conversion is item-owned.
 *      `demo-pig-blue` bag = 25 kg, `demo-pig-red` bag = 20 kg. Both cross COUNT → MASS.
 *      (The blue pigment's supplier code is already `PB-25KG`.)
 *   B. Several pack levels on one item, each stored directly against the stock unit and never
 *      chained through another pack (ADR 0050 rule 5): `demo-thinner` can = 5 L and drum = 200 L.
 *      The drum row says 200, not "40 cans", so no rounding compounds across hops.
 *   C. A conversion that crosses to LENGTH: `demo-masking-tape` roll = 50 m, transacted in whole
 *      rolls (per-override quantity precision 1). The precision is stored and shown on the
 *      screen, but `BuildPurchaseOrderLineAttributes` does not apply it to a purchase line, so
 *      it is a recorded fact rather than a behaviour to try.
 *   D. An override that must beat a same-dimension global conversion: `demo-sanding-disc`
 *      box = 50 ea. Box and each are both COUNT, so with no override the global service would
 *      say 1 box = 1 each — silently wrong. The item is also whole-discs-only through its own
 *      `quantity_precision` (seeded by InventoryDemoSeeder).
 *
 * Try each branch by hand on a purchase order line: 10 bags of `demo-pig-blue` → 250 kg, of
 * `demo-pig-red` → 200 kg; 10 rolls of `demo-masking-tape` → 500 m; 2 boxes of `demo-sanding-disc`
 * → 100 ea; `demo-thinner` in millilitres converts through the global service with no row here
 * at all (branch 2); and `demo-pig-blue` in drums is rejected because no row and no shared
 * dimension exist (branch 3).
 *
 * `demo-masking-tape` and `demo-sanding-disc` are also recorded as sold in their pack unit at
 * their supplier — see their item-supplier rows in PurchasingDemoSeeder, which is why this
 * seeder runs before it.
 *
 * Idempotency: a pack conversion is mutable reference data keyed by its natural identity
 * (item + pack unit, unique in the table), so `updateOrCreate` reconciles factor and precision on
 * every run without touching other rows. A conversion is never repointed — its item and unit are
 * the key — which is also what the model itself enforces.
 */
final class PackConversionsDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $scenarios = [
            // A. Same unit, different quantity per item.
            ['item' => 'demo-pig-blue', 'uom' => 'bag', 'factor' => '25.000000000', 'precision' => null],
            ['item' => 'demo-pig-red', 'uom' => 'bag', 'factor' => '20.000000000', 'precision' => null],
            // B. Two pack levels, each directly in the stock unit.
            ['item' => 'demo-thinner', 'uom' => 'can', 'factor' => '5.000000000', 'precision' => null],
            ['item' => 'demo-thinner', 'uom' => 'drum', 'factor' => '200.000000000', 'precision' => null],
            // C. Crosses COUNT → LENGTH; whole rolls only.
            ['item' => 'demo-masking-tape', 'uom' => 'roll', 'factor' => '50.000000000', 'precision' => '1.000000000'],
            // D. Same-dimension pack that must override the global 1:1.
            ['item' => 'demo-sanding-disc', 'uom' => 'box', 'factor' => '50.000000000', 'precision' => null],
        ];

        foreach ($scenarios as $scenario) {
            $item = Item::query()->where('code', $scenario['item'])->firstOrFail();
            $uom = Uom::query()->where('code', $scenario['uom'])->firstOrFail();

            ItemQuantityUnit::query()->updateOrCreate(
                ['item_id' => $item->getKey(), 'uom_id' => $uom->getKey()],
                ['factor' => $scenario['factor'], 'quantity_precision' => $scenario['precision']],
            );
        }
    }
}

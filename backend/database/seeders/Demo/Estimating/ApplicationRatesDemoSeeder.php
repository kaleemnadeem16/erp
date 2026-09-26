<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\RateDimensionValue;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Rates over demo-paint-white (InventoryDemoSeeder), tagged against
 * RateDimensionsDemoSeeder's Surface Type/Coat Count values, deliberately
 * shaped so the resolution tester (docs/domain/application-rates.md#resolution)
 * demonstrates every tier with zero manual setup. All per square metre
 * (work unit = sqm) unless noted:
 *
 *   request [smooth]              -> EXACT_MATCH          0.0833
 *   request [rough]               -> EXACT_MATCH          0.1333
 *   request [metal]               -> EXACT_MATCH          0.0714
 *   request [smooth, 1-coat]      -> EXACT_MATCH          0.0900
 *   request [smooth, 2-coat]      -> REQUIRED_ONLY_MATCH  0.0900 wins on
 *                                     Surface Type; nothing tags smooth+2-coat
 *                                     exactly
 *   request [metal, 1-coat]       -> REQUIRED_ONLY_MATCH  0.0714 — the
 *                                     optional Coat Count tag on the request
 *                                     is simply ignored once Surface Type
 *                                     (the required dimension) matches; this
 *                                     is what "required dimensions only,
 *                                     ignoring optional ones" means in
 *                                     practice, not a coincidence
 *   request []                    -> DEFAULT              0.1000
 *
 * A real loud-failure case needs an item+work-unit combination with no
 * default configured at all — once a default exists, tier 3 always catches
 * everything, by design. Work unit = metre (edge/trim touch-up, priced per
 * linear metre rather than area) has exactly one tagged rate and no
 * default:
 *
 *   request [smooth] (work unit = metre) -> EXACT_MATCH  0.5000
 *   request []        (work unit = metre) -> loud failure — nothing matches
 *                                             and no default is configured
 *                                             for this work unit
 *
 * application_rates has no natural business code, unlike RateDimension/
 * RateDimensionValue — rebuilding this item's demo rates from scratch each
 * run is simpler and just as idempotent as a partial-match updateOrCreate
 * would be.
 */
final class ApplicationRatesDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $item = Item::query()->where('code', 'demo-paint-white')->firstOrFail();
        $sqm = Uom::query()->where('code', 'sqm')->firstOrFail();
        $metre = Uom::query()->where('code', 'm')->firstOrFail();
        $litre = Uom::query()->where('code', 'l')->firstOrFail();

        ApplicationRate::query()
            ->where('item_id', $item->getKey())
            ->whereIn('work_uom_id', [$sqm->getKey(), $metre->getKey()])
            ->get()
            ->each(fn (ApplicationRate $rate) => $rate->delete());

        $smooth = RateDimensionValue::query()->where('code', 'demo-smooth')->firstOrFail();
        $rough = RateDimensionValue::query()->where('code', 'demo-rough')->firstOrFail();
        $metal = RateDimensionValue::query()->where('code', 'demo-metal')->firstOrFail();
        $oneCoat = RateDimensionValue::query()->where('code', 'demo-1-coat')->firstOrFail();

        $this->rate($item, $sqm, $litre, '0.083300', [$smooth->getKey()]);
        $this->rate($item, $sqm, $litre, '0.133300', [$rough->getKey()]);
        $this->rate($item, $sqm, $litre, '0.071400', [$metal->getKey()]);
        $this->rate($item, $sqm, $litre, '0.090000', [$smooth->getKey(), $oneCoat->getKey()]);
        $this->rate($item, $sqm, $litre, '0.100000', [], isDefault: true);

        // Per-metre trim/edge touch-up — deliberately has no default, so an
        // unmatched combination genuinely fails loudly instead of always
        // being rescued by tier 3.
        $this->rate($item, $metre, $litre, '0.500000', [$smooth->getKey()]);
    }

    /** @param list<int> $dimensionValueIds */
    private function rate(Item $item, Uom $workUom, Uom $uom, string $qty, array $dimensionValueIds, bool $isDefault = false): void
    {
        $rate = ApplicationRate::create([
            'item_id' => $item->getKey(),
            'work_uom_id' => $workUom->getKey(),
            'qty_per_work_unit' => $qty,
            'uom_id' => $uom->getKey(),
            'is_default' => $isDefault,
        ]);
        $rate->dimensionValues()->sync($dimensionValueIds);
    }
}

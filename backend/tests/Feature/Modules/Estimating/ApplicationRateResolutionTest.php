<?php

namespace Tests\Feature\Modules\Estimating;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Estimating\Actions\ResolveApplicationRate;
use App\Modules\Estimating\Models\ApplicationRate;
use App\Modules\Estimating\Models\RateDimension;
use App\Modules\Estimating\Models\RateDimensionValue;
use App\Modules\Estimating\Support\ApplicationRateCombinationGuard;
use App\Modules\Estimating\Support\ResolutionMethod;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Support\UomDimension;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises docs/domain/application-rates.md#resolution against the paint
 * worked example that doc itself uses (Shade #4471, Surface Type dimension,
 * smooth/rough/metal rates) — see application-rates.md's "Worked example —
 * paint" table.
 */
class ApplicationRateResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Item $shade;

    private Uom $sqm;

    private Uom $litre;

    private RateDimension $surfaceType;

    private RateDimensionValue $smooth;

    private RateDimensionValue $rough;

    private RateDimensionValue $metal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqm = Uom::create(['code' => 'sqm', 'name' => 'Square metre', 'dimension' => UomDimension::Area, 'factor_to_base' => '1']);
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1']);
        $category = Category::create(['code' => 'PAINT', 'name' => 'Paint', 'default_valuation_method' => 'AVCO']);
        $this->shade = Item::create(['code' => 'SHADE-4471', 'name' => 'Shade #4471', 'type' => 'FINISHED', 'stock_uom_id' => $this->litre->id, 'category_id' => $category->id]);

        $this->surfaceType = RateDimension::create(['code' => 'SURFACE', 'name' => 'Surface Type', 'is_required' => true]);
        $this->smooth = $this->surfaceType->values()->create(['code' => 'SMOOTH', 'name' => 'Smooth plaster']);
        $this->rough = $this->surfaceType->values()->create(['code' => 'ROUGH', 'name' => 'Rough plaster']);
        $this->metal = $this->surfaceType->values()->create(['code' => 'METAL', 'name' => 'Metal']);
    }

    /** @param list<int> $dimensionValueIds */
    private function rate(string $qty, array $dimensionValueIds = [], bool $isDefault = false): ApplicationRate
    {
        $rate = ApplicationRate::create([
            'item_id' => $this->shade->id, 'work_uom_id' => $this->sqm->id,
            'qty_per_work_unit' => $qty, 'uom_id' => $this->litre->id, 'is_default' => $isDefault,
        ]);
        $rate->dimensionValues()->sync($dimensionValueIds);

        return $rate;
    }

    public function test_exact_match_wins_over_default(): void
    {
        $smoothRate = $this->rate('0.0833', [$this->smooth->id]);
        $this->rate('0.1000', [], true);

        $resolution = (new ResolveApplicationRate)($this->shade->id, $this->sqm->id, [$this->smooth->id]);

        $this->assertSame($smoothRate->id, $resolution->rate->id);
        $this->assertSame(ResolutionMethod::ExactMatch, $resolution->method);
    }

    public function test_no_dimension_value_supplied_falls_back_to_default_not_a_tagged_rate(): void
    {
        $this->rate('0.0833', [$this->smooth->id]);
        $default = $this->rate('0.1000', [], true);

        $resolution = (new ResolveApplicationRate)($this->shade->id, $this->sqm->id, []);

        $this->assertSame($default->id, $resolution->rate->id);
        $this->assertSame(ResolutionMethod::Default, $resolution->method);
    }

    public function test_required_only_match_ignores_an_unmatched_optional_dimension(): void
    {
        $coatCount = RateDimension::create(['code' => 'COATS', 'name' => 'Coat Count', 'is_required' => false]);
        $oneCoat = $coatCount->values()->create(['code' => '1COAT', 'name' => 'One coat']);
        $twoCoat = $coatCount->values()->create(['code' => '2COAT', 'name' => 'Two coats']);

        $smoothOneCoat = $this->rate('0.0833', [$this->smooth->id, $oneCoat->id]);
        $this->rate('0.1000', [], true);

        // Requests smooth + two coats — no rate is tagged with exactly that
        // combination, but the smooth+one-coat rate matches on the required
        // Surface Type dimension, so it wins over the default.
        $resolution = (new ResolveApplicationRate)($this->shade->id, $this->sqm->id, [$this->smooth->id, $twoCoat->id]);

        $this->assertSame($smoothOneCoat->id, $resolution->rate->id);
        $this->assertSame(ResolutionMethod::RequiredOnlyMatch, $resolution->method);
    }

    public function test_no_match_and_no_default_fails_loudly(): void
    {
        $this->rate('0.0833', [$this->smooth->id]);

        $this->expectException(DomainInvariantException::class);

        (new ResolveApplicationRate)($this->shade->id, $this->sqm->id, [$this->rough->id]);
    }

    public function test_the_paint_worked_example_resolves_each_surface_correctly(): void
    {
        $smoothRate = $this->rate('0.0833', [$this->smooth->id]);
        $roughRate = $this->rate('0.1333', [$this->rough->id]);
        $metalRate = $this->rate('0.0714', [$this->metal->id]);

        $resolve = new ResolveApplicationRate;

        $this->assertSame($smoothRate->id, $resolve($this->shade->id, $this->sqm->id, [$this->smooth->id])->rate->id);
        $this->assertSame($roughRate->id, $resolve($this->shade->id, $this->sqm->id, [$this->rough->id])->rate->id);
        $this->assertSame($metalRate->id, $resolve($this->shade->id, $this->sqm->id, [$this->metal->id])->rate->id);
    }

    public function test_duplicate_exact_combination_is_rejected_at_write_time(): void
    {
        $this->rate('0.0833', [$this->smooth->id]);

        $this->expectException(DomainInvariantException::class);

        (new ApplicationRateCombinationGuard)($this->shade->id, $this->sqm->id, [$this->smooth->id]);
    }

    public function test_only_one_default_rate_is_allowed_per_item_and_work_unit(): void
    {
        $this->rate('0.1000', [], true);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->rate('0.1200', [], true);
    }

    public function test_an_application_rate_cannot_be_repointed_to_a_different_item(): void
    {
        $rate = $this->rate('0.0833', [$this->smooth->id]);
        $otherItem = Item::create(['code' => 'OTHER', 'name' => 'Other', 'type' => 'FINISHED', 'stock_uom_id' => $this->litre->id, 'category_id' => $this->shade->category_id]);

        $this->expectException(DomainInvariantException::class);

        $rate->update(['item_id' => $otherItem->id]);
    }
}

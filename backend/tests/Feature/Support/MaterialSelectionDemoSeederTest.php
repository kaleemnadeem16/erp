<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Estimating\Actions\ResolveMaterialSelectionStrategies;
use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use App\Modules\Estimating\Support\MaterialSelectionSource;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Estimating\Support\ResolvedSelectionStrategy;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** MaterialSelectionDemoSeeder claims each level resolves for a demo material; proved through the real resolver. */
final class MaterialSelectionDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    private function resolve(string $code): ResolvedSelectionStrategy
    {
        $id = Item::query()->where('code', $code)->firstOrFail()->id;

        return app(ResolveMaterialSelectionStrategies::class)([$id])[$id];
    }

    public function test_each_level_answers_for_its_own_demo_material(): void
    {
        $pigment = $this->resolve('demo-pig-blue');
        $this->assertSame(MaterialSelectionSource::Item, $pigment->source);
        $this->assertSame(MaterialSelectionStrategy::LowestInternalCost, $pigment->strategy);

        $thinner = $this->resolve('demo-thinner');
        $this->assertSame(MaterialSelectionSource::ItemCategory, $thinner->source);
        $this->assertSame(MaterialSelectionStrategy::FewestPackages, $thinner->strategy);

        $paint = $this->resolve('demo-paint-white');
        $this->assertSame(MaterialSelectionSource::BuiltIn, $paint->source);
        $this->assertSame(MaterialSelectionStrategy::MinimiseWaste, $paint->strategy);
    }

    public function test_the_category_shows_a_real_change_in_its_history_and_reseeding_adds_nothing(): void
    {
        $count = MaterialSelectionPolicy::query()->count();
        $this->assertSame(1, MaterialSelectionPolicy::query()->where('scope_type', 'ITEM_CATEGORY')->where('status', 'SUPERSEDED')->count());

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($count, MaterialSelectionPolicy::query()->count());
    }
}

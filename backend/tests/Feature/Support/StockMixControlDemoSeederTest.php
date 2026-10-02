<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Estimating\Actions\ResolveStockMixControls;
use App\Modules\Estimating\Models\StockMixPolicy;
use App\Modules\Estimating\Support\MaterialSelectionSource;
use App\Modules\Estimating\Support\StockMixLevel;
use App\Modules\Inventory\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** StockMixControlDemoSeeder claims a company rule is in force for every demo material; proved through the real resolver. */
final class StockMixControlDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_the_demo_company_rule_applies_to_every_demo_material(): void
    {
        $id = Item::query()->where('code', 'demo-pig-blue')->firstOrFail()->id;

        $control = app(ResolveStockMixControls::class)([$id])[$id];

        $this->assertSame(StockMixLevel::ReasonRequired, $control->level);
        $this->assertSame('10.0000', $control->thresholdPercent);
        $this->assertSame(MaterialSelectionSource::Company, $control->source);
    }

    public function test_re_seeding_adds_nothing(): void
    {
        $before = StockMixPolicy::query()->count();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($before, StockMixPolicy::query()->count());
    }
}

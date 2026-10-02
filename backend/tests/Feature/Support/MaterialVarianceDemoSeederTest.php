<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Modules\Estimating\Actions\EvaluateMaterialVariance;
use App\Modules\Estimating\Actions\ResolveMaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Support\MaterialVarianceEvaluation;
use App\Modules\Estimating\Support\MaterialVariancePolicyResolution;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceResolutionMethod;
use App\Modules\Inventory\Models\Item;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MaterialVarianceDemoSeeder claims three tiers resolving item → category → company. Each claim is
 * proved through the real resolver and evaluator, not by reading the rows back.
 */
final class MaterialVarianceDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('demo:seed')->assertSuccessful();
    }

    public function test_an_example_reason_list_is_offered_and_every_reason_is_active(): void
    {
        $this->assertGreaterThanOrEqual(4, MaterialVarianceReason::query()->count());
        $this->assertSame(0, MaterialVarianceReason::query()->where('active', false)->count());
    }

    public function test_each_tier_resolves_by_its_own_scope(): void
    {
        $this->assertSame(VarianceResolutionMethod::Item, $this->resolve('demo-pig-blue')->method);
        $this->assertSame(VarianceResolutionMethod::Category, $this->resolve('demo-thinner')->method);
        // The white paint's category has no policy of its own, so the company tier answers.
        $this->assertSame(VarianceResolutionMethod::Company, $this->resolve('demo-paint-white')->method);
    }

    public function test_the_item_tier_asks_for_a_reason_from_two_kilos_over(): void
    {
        $this->assertSame(VarianceEffect::ReasonRequired, $this->judge('demo-pig-blue', '10', '12.5')->effect);
        // 1 kg over is 10% of the standard: below the item's own 2 kg limit; the company tier's 10% does not stack on top of it.
        $this->assertSame(VarianceEffect::AuditOnly, $this->judge('demo-pig-blue', '10', '11')->effect);
    }

    public function test_the_category_tier_blocks_a_large_solvent_overuse(): void
    {
        $this->assertSame(VarianceEffect::AuditOnly, $this->judge('demo-thinner', '100', '104')->effect);
        $this->assertSame(VarianceEffect::ReasonRequired, $this->judge('demo-thinner', '100', '110')->effect);
        $this->assertSame(VarianceEffect::Blocked, $this->judge('demo-thinner', '100', '150')->effect);
    }

    public function test_the_company_tier_covers_everything_else_in_both_directions(): void
    {
        $this->assertSame(VarianceEffect::AuditOnly, $this->judge('demo-paint-white', '100', '105')->effect);
        $this->assertSame(VarianceEffect::ReasonRequired, $this->judge('demo-paint-white', '100', '115')->effect);
        $this->assertSame(VarianceEffect::AuditOnly, $this->judge('demo-paint-white', '100', '90')->effect);
        $this->assertSame(VarianceEffect::ReasonRequired, $this->judge('demo-paint-white', '100', '70')->effect);
    }

    public function test_reseeding_changes_nothing(): void
    {
        $before = MaterialVariancePolicy::query()->count();
        $reasons = MaterialVarianceReason::query()->count();

        $this->artisan('demo:seed')->assertSuccessful();

        $this->assertSame($before, MaterialVariancePolicy::query()->count());
        $this->assertSame($reasons, MaterialVarianceReason::query()->count());
    }

    private function resolve(string $code): MaterialVariancePolicyResolution
    {
        return app(ResolveMaterialVariancePolicy::class)($this->item($code));
    }

    private function judge(string $code, string $standard, string $planned): MaterialVarianceEvaluation
    {
        $item = $this->item($code);

        return app(EvaluateMaterialVariance::class)($item, BigDecimal::of($standard), BigDecimal::of($planned), $this->resolve($code), null);
    }

    private function item(string $code): Item
    {
        return Item::query()->where('code', $code)->firstOrFail();
    }
}

<?php

namespace Tests\Feature\Modules\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\EvaluateMaterialVariance;
use App\Modules\Estimating\Actions\ResolveMaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Support\EstimateCostResolution;
use App\Modules\Estimating\Support\MaterialVarianceEvaluation;
use App\Modules\Estimating\Support\VarianceCostComparison;
use App\Modules\Estimating\Support\VarianceEffect;
use App\Modules\Estimating\Support\VarianceResolutionMethod;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\Costing\CostReadSession;
use App\Support\UomDimension;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR 0054's evaluation, checked against hand-worked figures. The running example: a 6.5 L standard
 * planned as 8 L is +1.5 L, +23.076923 %, and at a unit cost of 20 a cost impact of 30.
 */
class MaterialVarianceEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Uom $litre;

    private Uom $millilitre;

    private Uom $kg;

    private Item $paint;

    private Category $paints;

    private Category $coatings;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '1', 'is_base' => true]);
        $this->millilitre = Uom::create(['code' => 'ml', 'name' => 'Millilitre', 'dimension' => UomDimension::Volume, 'factor_to_base' => '0.001']);
        $this->kg = Uom::create(['code' => 'kg', 'name' => 'Kilogram', 'dimension' => UomDimension::Mass, 'factor_to_base' => '1', 'is_base' => true]);
        $this->coatings = Category::create(['code' => 'COATINGS', 'name' => 'Coatings', 'default_valuation_method' => 'AVCO']);
        $this->paints = Category::create(['code' => 'PAINTS', 'name' => 'Paints', 'parent_id' => $this->coatings->id, 'default_valuation_method' => 'AVCO']);
        $this->paint = Item::create(['code' => 'PAINT', 'name' => 'Paint', 'type' => 'RAW', 'stock_uom_id' => $this->litre->id, 'category_id' => $this->paints->id]);
        $this->user = User::factory()->create();
    }

    public function test_an_unchanged_quantity_has_no_effect(): void
    {
        $result = $this->evaluate('6.5', '6.5');

        $this->assertNull($result->effect);
        $this->assertFalse($result->isAdjusted());
        $this->assertSame('0.0', (string) $result->delta);
    }

    public function test_without_any_policy_every_difference_needs_a_reason_in_either_direction(): void
    {
        foreach (['8', '6'] as $planned) {
            $result = $this->evaluate('6.5', $planned);

            $this->assertSame(VarianceEffect::ReasonRequired, $result->effect, "planned {$planned}");
            $this->assertSame(VarianceResolutionMethod::None, $result->method);
            $this->assertNull($result->policyId);
            $this->assertSame('DEFAULT', $result->matched[0]['source']);
        }
    }

    public function test_the_running_example_is_measured_exactly(): void
    {
        $result = $this->evaluate('6.5', '8');

        $this->assertSame('1.5', (string) $result->delta);
        $this->assertSame('23.076923', (string) $result->deltaPercent);
        $this->assertSame('OVER', $result->direction->value);
    }

    public function test_the_strongest_matching_band_wins_and_below_every_band_is_recorded_only(): void
    {
        $this->policy('COMPANY', null, [
            ['OVER', 'PERCENT', '10', null, 'REASON_REQUIRED'],
            ['OVER', 'PERCENT', '30', null, 'BLOCKED'],
        ]);

        $this->assertSame(VarianceEffect::AuditOnly, $this->evaluate('6.5', '6.7')->effect);       // +3.08 %
        $this->assertSame(VarianceEffect::ReasonRequired, $this->evaluate('6.5', '7.5')->effect);  // +15.38 %
        $blocked = $this->evaluate('6.5', '10');                                                    // +53.85 %: both bands match
        $this->assertSame(VarianceEffect::Blocked, $blocked->effect);
        $this->assertCount(2, $blocked->matched);
    }

    public function test_strength_decides_not_the_order_the_bands_were_entered_in(): void
    {
        // The stronger band is listed first; a "last match wins" evaluator would answer REASON_REQUIRED.
        $this->policy('COMPANY', null, [
            ['OVER', 'PERCENT', '30', null, 'BLOCKED'],
            ['OVER', 'PERCENT', '10', null, 'REASON_REQUIRED'],
        ]);

        $this->assertSame(VarianceEffect::Blocked, $this->evaluate('6.5', '10')->effect);
    }

    public function test_a_band_matches_exactly_at_its_threshold(): void
    {
        $this->policy('COMPANY', null, [['OVER', 'PERCENT', '10', null, 'REASON_REQUIRED']]);

        // 6.5 -> 7.15 is exactly +10 %.
        $this->assertSame(VarianceEffect::ReasonRequired, $this->evaluate('6.5', '7.15')->effect);
        $this->assertSame(VarianceEffect::AuditOnly, $this->evaluate('6.5', '7.149999')->effect);
    }

    public function test_over_and_under_plan_have_separate_bands(): void
    {
        $this->policy('COMPANY', null, [
            ['OVER', 'PERCENT', '10', null, 'BLOCKED'],
            ['UNDER', 'PERCENT', '10', null, 'REASON_REQUIRED'],
        ]);

        $this->assertSame(VarianceEffect::Blocked, $this->evaluate('10', '12')->effect);
        $this->assertSame(VarianceEffect::ReasonRequired, $this->evaluate('10', '8')->effect);
        $this->assertSame(VarianceEffect::AuditOnly, $this->evaluate('10', '9.5')->effect);
    }

    public function test_a_quantity_band_names_its_own_unit_and_converts(): void
    {
        // Over by 1,500 ml or more; the paint is stocked in litres and the difference is 1.5 L.
        $this->policy('COMPANY', null, [['OVER', 'QUANTITY', '1500', $this->millilitre, 'REASON_REQUIRED']]);

        $result = $this->evaluate('6.5', '8');

        $this->assertSame(VarianceEffect::ReasonRequired, $result->effect);
        $this->assertSame(['1500', 'ml'], [$result->matched[0]['observed'], $result->matched[0]['unit']]);
        $this->assertSame(VarianceEffect::AuditOnly, $this->evaluate('6.5', '7.9')->effect);
    }

    public function test_a_quantity_band_in_a_unit_that_does_not_convert_is_skipped_and_reported_not_passed(): void
    {
        $this->policy('COMPANY', null, [['OVER', 'QUANTITY', '0.1', $this->kg, 'BLOCKED']]);

        $result = $this->evaluate('6.5', '100');

        $this->assertSame(VarianceEffect::AuditOnly, $result->effect);
        $this->assertCount(1, $result->skipped);
        $this->assertStringContainsString('does not convert', $result->skipped[0]['reason']);
    }

    public function test_cost_bands_measure_the_internal_cost_impact(): void
    {
        $this->policy('COMPANY', null, [
            ['OVER', 'COST_AMOUNT', '25', null, 'REASON_REQUIRED'],
            ['OVER', 'COST_PERCENT', '30', null, 'BLOCKED'],
        ]);

        // 1.5 L × 20 = 30 (≥ 25); 30 / (6.5 × 20 = 130) = 23.08 % (< 30).
        $result = $this->evaluate('6.5', '8', BigDecimal::of('20'));

        $this->assertSame(VarianceEffect::ReasonRequired, $result->effect);
        $this->assertSame(['30', null], [$result->matched[0]['observed'], $result->matched[0]['unit']]);
        $this->assertCount(1, $result->matched);
    }

    public function test_cost_bands_are_skipped_without_cost_evidence_never_treated_as_zero(): void
    {
        $this->policy('COMPANY', null, [['OVER', 'COST_AMOUNT', '1', null, 'BLOCKED']]);

        $result = $this->evaluate('6.5', '8', null);

        $this->assertSame(VarianceEffect::AuditOnly, $result->effect);
        $this->assertSame('There is no complete internal cost comparison for this item.', $result->skipped[0]['reason']);
    }

    public function test_a_zero_standard_skips_percentages_but_absolute_rules_still_apply(): void
    {
        $this->policy('COMPANY', null, [
            ['OVER', 'PERCENT', '10', null, 'BLOCKED'],
            ['OVER', 'QUANTITY', '1', $this->litre, 'REASON_REQUIRED'],
        ]);

        $result = $this->evaluate('0', '2');

        $this->assertSame(VarianceEffect::ReasonRequired, $result->effect);
        $this->assertNull($result->deltaPercent);
        $this->assertCount(1, $result->skipped);
        $this->assertStringContainsString('zero', $result->limitations[0]);
    }

    public function test_the_policys_minimum_effect_is_a_floor_under_the_bands(): void
    {
        $this->policy('COMPANY', null, [['OVER', 'PERCENT', '50', null, 'BLOCKED']], minimum: 'REASON_REQUIRED');

        $small = $this->evaluate('6.5', '6.6');

        $this->assertSame(VarianceEffect::ReasonRequired, $small->effect);
        $this->assertSame('MINIMUM', $small->matched[0]['source']);
        $this->assertNull($this->evaluate('6.5', '6.5')->effect); // still nothing for no adjustment
    }

    public function test_an_approval_band_blocks_rather_than_degrading_to_a_reason(): void
    {
        $this->policy('COMPANY', null, [['OVER', 'PERCENT', '10', null, 'APPROVAL_REQUIRED']]);

        $result = $this->evaluate('6.5', '8');

        $this->assertSame(VarianceEffect::Blocked, $result->effect);
        $this->assertStringContainsString('approval workflow is not available', $result->limitations[0]);
    }

    public function test_the_policy_resolves_item_then_category_then_parent_category_then_company(): void
    {
        $resolve = fn (): VarianceResolutionMethod => (new ResolveMaterialVariancePolicy)($this->paint->fresh())->method;
        $this->assertSame(VarianceResolutionMethod::None, $resolve());

        $company = $this->policy('COMPANY', null, []);
        $this->assertSame(VarianceResolutionMethod::Company, $resolve());

        $parent = $this->policy('CATEGORY', $this->coatings->id, []);
        $this->assertSame([VarianceResolutionMethod::Category, $parent->id], [$resolve(), (new ResolveMaterialVariancePolicy)($this->paint->fresh())->policy->id]);

        $own = $this->policy('CATEGORY', $this->paints->id, []);
        $this->assertSame($own->id, (new ResolveMaterialVariancePolicy)($this->paint->fresh())->policy->id);

        $item = $this->policy('ITEM', $this->paint->id, []);
        $resolution = (new ResolveMaterialVariancePolicy)($this->paint->fresh());
        $this->assertSame([VarianceResolutionMethod::Item, $item->id], [$resolution->method, $resolution->policy->id]);
        $this->assertNotSame($company->id, $resolution->policy->id);
    }

    public function test_only_an_active_policy_resolves(): void
    {
        $this->policy('COMPANY', null, [], status: 'SUPERSEDED');
        $this->policy('COMPANY', null, [], status: 'DRAFT');

        $this->assertSame(VarianceResolutionMethod::None, (new ResolveMaterialVariancePolicy)($this->paint->fresh())->method);
    }

    public function test_the_database_refuses_shapes_the_rules_forbid(): void
    {
        $this->policy('COMPANY', null, []);

        // A second ACTIVE policy for the same scope.
        $this->assertDatabaseRejects(fn () => $this->policy('COMPANY', null, [], from: now()->addDays(40)));
        // A quantity band without a unit, and a non-quantity band with one.
        $policy = $this->policy('CATEGORY', $this->coatings->id, []);
        $this->assertDatabaseRejects(fn () => $policy->bands()->create(['direction' => 'OVER', 'measure' => 'QUANTITY', 'threshold' => '1', 'effect' => 'BLOCKED']));
        $this->assertDatabaseRejects(fn () => $policy->bands()->create(['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '1', 'uom_id' => $this->litre->id, 'effect' => 'BLOCKED']));
        // A negative threshold, an unknown effect, and a CATEGORY scope with no id.
        $this->assertDatabaseRejects(fn () => $policy->bands()->create(['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '-1', 'effect' => 'BLOCKED']));
        // Raw insert: the model's enum cast would refuse the value before the database ever saw it.
        $this->assertDatabaseRejects(fn () => DB::table('material_variance_policy_bands')->insert([
            'policy_id' => $policy->id, 'direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '1', 'effect' => 'SHRUG',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertDatabaseRejects(fn () => $this->policy('CATEGORY', null, [], status: 'DRAFT'));
    }

    private function assertDatabaseRejects(callable $write): void
    {
        try {
            DB::transaction($write);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The database accepted a shape the rules forbid.');
    }

    private function evaluate(string $standard, string $planned, ?BigDecimal $cost = null): MaterialVarianceEvaluation
    {
        $resolution = (new ResolveMaterialVariancePolicy)($this->paint->fresh());

        $comparison = null;
        if ($cost !== null) {
            $builder = app(EstimateCostResolution::class);
            $session = new CostReadSession(CarbonImmutable::now());
            $comparison = new VarianceCostComparison(
                $builder->configured(BigDecimal::of($standard)->multipliedBy($cost), BigDecimal::of($standard), $this->litre->id, 'REQUIRED_QUANTITY', $session, []),
                $builder->configured(BigDecimal::of($planned)->multipliedBy($cost), BigDecimal::of($planned), $this->litre->id, 'REQUIRED_QUANTITY', $session, []),
            );
        }

        return app(EvaluateMaterialVariance::class)($this->paint->fresh(), BigDecimal::of($standard), BigDecimal::of($planned), $resolution, $comparison);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: Uom|null, 4: string}>  $bands  direction, measure, threshold, unit, effect
     */
    private function policy(string $scope, ?int $scopeId, array $bands, string $status = 'ACTIVE', ?string $minimum = null, mixed $from = null): MaterialVariancePolicy
    {
        $policy = MaterialVariancePolicy::create([
            'scope_type' => $scope, 'scope_id' => $scopeId, 'minimum_effect' => $minimum, 'reason' => 'Test policy',
            'status' => $status, 'changed_by' => $this->user->id,
            'effective_from' => $status === 'DRAFT' ? null : ($from ?? now()->subDays(30 + random_int(0, 5)))->toDateString(),
            'effective_to' => $status === 'SUPERSEDED' ? now()->subDay()->toDateString() : null,
        ]);
        foreach ($bands as [$direction, $measure, $threshold, $uom, $effect]) {
            $policy->bands()->create(['direction' => $direction, 'measure' => $measure, 'threshold' => $threshold, 'uom_id' => $uom?->id, 'effect' => $effect]);
        }

        return $policy->load('bands.uom');
    }
}

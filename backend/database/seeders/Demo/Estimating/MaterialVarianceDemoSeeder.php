<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\Uom;
use App\Models\User;
use App\Modules\Estimating\Actions\ActivateMaterialVariancePolicy;
use App\Modules\Estimating\Actions\CreateMaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVariancePolicy;
use App\Modules\Estimating\Models\MaterialVarianceReason;
use App\Modules\Estimating\Support\MaterialVariancePolicyStatus;
use App\Modules\Estimating\Support\VarianceScopeType;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Example configuration for ADR 0054's material-variance control. A paint company is only the
 * illustration: reasons and rules are company data, and a real company enters its own on
 * Settings → Material variance. Nothing here is seeded by a migration or shipped as a default.
 *
 * Three tiers, so the Material Preview shows the hierarchy resolving (item → category → company):
 *   - COMPANY: more than 10% over plan, or 25% under, needs a reason.
 *   - CATEGORY "demo-solvents": over 5% needs a reason and over 40% is blocked (solvent overuse is a
 *     safety matter, not just a cost one).
 *   - ITEM "demo-pig-blue": 2 kg over needs a reason, and so does a cost impact over 15% — the cost
 *     band is reported skipped where the pigment has no cost evidence.
 *
 * A scope that already has an ACTIVE policy is left alone (active versions are frozen history), and
 * reasons are matched by code, so re-running demo:seed changes nothing.
 */
final class MaterialVarianceDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    private const REASONS = [
        ['code' => 'spillage', 'name' => 'Spillage or wastage on site', 'description' => 'Material lost while mixing, applying or moving it.'],
        ['code' => 'rough-surface', 'name' => 'Surface rougher than expected', 'description' => 'The surface soaked up more material than the standard assumes.'],
        ['code' => 'extra-coat', 'name' => 'Extra coat requested', 'description' => 'The customer asked for more coverage than quoted.'],
        ['code' => 'remeasured', 'name' => 'Area re-measured on site', 'description' => 'The measured area differs from the one the standard was calculated for.'],
        ['code' => 'leftover-stock', 'name' => 'Using up leftover stock', 'description' => 'Planning less than the standard because existing stock covers part of the work.'],
    ];

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        foreach (self::REASONS as $reason) {
            MaterialVarianceReason::query()->updateOrCreate(['code' => $reason['code']], $reason + ['active' => true]);
        }

        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $this->ensureActivePolicy(VarianceScopeType::Company, null, 'Onboarding default: explain any large difference from the standard.', [
            ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '10', 'uom_id' => null, 'effect' => 'REASON_REQUIRED'],
            ['direction' => 'UNDER', 'measure' => 'PERCENT', 'threshold' => '25', 'uom_id' => null, 'effect' => 'REASON_REQUIRED'],
        ], $actor);

        $solvents = Category::query()->where('code', 'demo-solvents')->first();
        if ($solvents !== null) {
            $this->ensureActivePolicy(VarianceScopeType::Category, $solvents->id, 'Solvents are a safety matter: tighter limits than the company default.', [
                ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '5', 'uom_id' => null, 'effect' => 'REASON_REQUIRED'],
                ['direction' => 'OVER', 'measure' => 'PERCENT', 'threshold' => '40', 'uom_id' => null, 'effect' => 'BLOCKED'],
            ], $actor);
        }

        $pigment = Item::query()->where('code', 'demo-pig-blue')->first();
        $kg = Uom::query()->where('code', 'kg')->first();
        if ($pigment !== null && $kg !== null) {
            $this->ensureActivePolicy(VarianceScopeType::Item, $pigment->id, 'Phthalo blue is expensive: watch the absolute amount and the cost impact.', [
                ['direction' => 'OVER', 'measure' => 'QUANTITY', 'threshold' => '2', 'uom_id' => $kg->id, 'effect' => 'REASON_REQUIRED'],
                ['direction' => 'OVER', 'measure' => 'COST_PERCENT', 'threshold' => '15', 'uom_id' => null, 'effect' => 'REASON_REQUIRED'],
            ], $actor);
        }
    }

    /** @param list<array<string, mixed>> $bands */
    private function ensureActivePolicy(VarianceScopeType $scope, ?int $scopeId, string $reason, array $bands, User $actor): void
    {
        $alreadyActive = MaterialVariancePolicy::query()
            ->forScope($scope, $scopeId)
            ->where('status', MaterialVariancePolicyStatus::Active)
            ->exists();
        if ($alreadyActive) {
            return;
        }

        $policy = (new CreateMaterialVariancePolicy)([
            'scope_type' => $scope, 'scope_id' => $scopeId, 'reason' => $reason, 'bands' => $bands,
        ], $actor);

        (new ActivateMaterialVariancePolicy)($policy, $actor);
    }
}

<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\User;
use App\Modules\Estimating\Actions\SetMaterialSelectionPolicy;
use App\Modules\Estimating\Models\MaterialSelectionPolicy;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\MaterialSelectionStrategy;
use App\Modules\Inventory\Models\Category;
use App\Modules\Inventory\Models\Item;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Example stored selection preferences (ADR 0053 §2, ADR 0071) so the Material Preview and
 * Settings → Material selection show every level resolving. A company enters its own; nothing
 * here is a shipped default.
 *
 *   - Category "demo-solvents": FEWEST_PACKAGES — the thinner comes in drums nobody wants to open
 *     for a small job. It was LOWEST_INTERNAL_COST first, so the history shows a real change.
 *   - Item "demo-pig-blue": LOWEST_INTERNAL_COST — phthalo blue is expensive, cost decides.
 *   - The company itself has no preference, so every other material (the white paint) falls back
 *     to the built-in MINIMISE_WASTE.
 *
 * A scope that already has any stored row is left alone, so re-running demo:seed changes nothing
 * and never rewrites history someone made by hand.
 */
final class MaterialSelectionDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        $set = app(SetMaterialSelectionPolicy::class);

        $solvents = Category::query()->where('code', 'demo-solvents')->first();
        if ($solvents !== null && ! $this->hasRows(MaterialSelectionScope::ItemCategory, $solvents->id)) {
            $set(MaterialSelectionScope::ItemCategory, $solvents->id, MaterialSelectionStrategy::LowestInternalCost, 'Onboarding: buy and use the cheapest solvent first.', $actor);
            $set(MaterialSelectionScope::ItemCategory, $solvents->id, MaterialSelectionStrategy::FewestPackages, 'Drums are awkward to open for small jobs: use as few as possible.', $actor);
        }

        $pigment = Item::query()->where('code', 'demo-pig-blue')->first();
        if ($pigment !== null && ! $this->hasRows(MaterialSelectionScope::Item, $pigment->id)) {
            $set(MaterialSelectionScope::Item, $pigment->id, MaterialSelectionStrategy::LowestInternalCost, 'Phthalo blue is expensive: draw the cheapest stock first.', $actor);
        }
    }

    private function hasRows(MaterialSelectionScope $scope, int $scopeId): bool
    {
        return MaterialSelectionPolicy::query()->where('scope_type', $scope)->where('scope_id', $scopeId)->exists();
    }
}

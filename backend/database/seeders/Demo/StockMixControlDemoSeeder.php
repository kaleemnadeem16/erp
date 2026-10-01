<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Models\User;
use App\Modules\Estimating\Actions\SetStockMixPolicy;
use App\Modules\Estimating\Models\StockMixPolicy;
use App\Modules\Estimating\Support\MaterialSelectionScope;
use App\Modules\Estimating\Support\StockMixLevel;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Sets the demo company's control over hand-chosen stock (ADR 0076): a stricter-than-default example so Settings →
 * Material selection shows a configured rule, and the Material Preview's "Choose the stock yourself" offers a reason box —
 * "reason needed when a mix costs 10% or more above the proposal, per unit".
 *
 * It cannot be seen *firing* in the demo yet: the rule only bites on materials costed per delivery or batch, and assembly
 * costing supports only average-costed materials today (formulations.md), so no such material can be previewed with cost.
 * The firing is proved on FIFO stock by StockMixControlTest instead.
 *
 * Set once and never overwritten when a company already stored a rule.
 */
final class StockMixControlDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        if (StockMixPolicy::query()->where('scope_type', MaterialSelectionScope::Company)->exists()) {
            return;
        }
        $actor = User::query()->where('email', config('demo.user.email'))->firstOrFail();
        app(SetStockMixPolicy::class)(
            MaterialSelectionScope::Company, null, StockMixLevel::ReasonRequired, '10',
            'Demo company: stock chosen by hand may cost up to 10% more per unit than the proposal; beyond that, say why.', $actor->id,
        );
    }
}

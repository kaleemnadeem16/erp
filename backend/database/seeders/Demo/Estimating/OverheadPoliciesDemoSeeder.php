<?php

declare(strict_types=1);

namespace Database\Seeders\Demo\Estimating;

use App\Models\User;
use App\Modules\Estimating\Actions\ActivateOverheadPolicy;
use App\Modules\Estimating\Models\AssemblyGroup;
use App\Modules\Estimating\Models\OverheadPolicy;
use App\Modules\Estimating\Support\OverheadPolicyStatus;
use App\Modules\Estimating\Support\OverheadScopeType;
use Database\Seeders\Demo\Concerns\EnsuresDemoEnvironment;
use Illuminate\Database\Seeder;

/**
 * Step 2's resolver (ADR 0049), demonstrated with two tiers so the UI and
 * API show the hierarchy actually resolving, not just a lone company
 * default:
 *
 *   - COMPANY: 10% of direct cost — the fallback every assembly gets unless
 *     something more specific applies.
 *   - ASSEMBLY_GROUP "demo-labour" (AssembliesDemoSeeder's Labour &
 *     Installation group): $5 per labour hour — overrides the company
 *     default for demo-asm-wall-paint, which belongs to that group.
 *
 * demo-asm-consult (AssembliesDemoSeeder's ungrouped assembly) deliberately
 * gets no override of its own, so it resolves straight through to the
 * company default — the "small company" case ADR 0049 requires still work
 * correctly even inside a company that also configured group-level detail
 * elsewhere.
 *
 * Policies are immutable once ACTIVE (see OverheadPolicy), so this only
 * creates a scope's policy if it doesn't already have an active one —
 * re-running demo:seed must not fail trying to recreate a frozen row.
 */
final class OverheadPoliciesDemoSeeder extends Seeder
{
    use EnsuresDemoEnvironment;

    public function run(): void
    {
        $this->ensureDemoEnvironment();

        $demoUser = User::query()->where('email', config('demo.user.email'))->firstOrFail();

        $this->ensureActivePolicy(
            OverheadScopeType::Company, null, 'PCT_DIRECT_COST', '10',
            'Onboarding default — flat percentage of direct cost.', $demoUser,
        );

        $labour = AssemblyGroup::query()->where('code', 'demo-labour')->first();
        if ($labour !== null) {
            $this->ensureActivePolicy(
                OverheadScopeType::AssemblyGroup, $labour->id, 'PER_LABOUR_HOUR', '5',
                'Labour & Installation work carries its own hourly overhead rate, not the company default.',
                $demoUser,
            );
        }
    }

    private function ensureActivePolicy(
        OverheadScopeType $scopeType,
        ?int $scopeId,
        string $driverType,
        string $rate,
        string $reason,
        User $actor,
    ): void {
        $alreadyActive = OverheadPolicy::query()
            ->forScope($scopeType, $scopeId)
            ->where('status', OverheadPolicyStatus::Active)
            ->exists();

        if ($alreadyActive) {
            return;
        }

        $policy = OverheadPolicy::create([
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'driver_type' => $driverType,
            'rate' => $rate, 'reason' => $reason, 'changed_by' => $actor->id,
        ])->refresh();

        (new ActivateOverheadPolicy)($policy);
    }
}

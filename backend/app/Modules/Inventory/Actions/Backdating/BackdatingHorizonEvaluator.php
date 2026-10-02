<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Backdating;

use App\Exceptions\DomainInvariantException;
use App\Models\Company;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Support\BackdatingHorizonMode;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Modules\Inventory\Support\PostingFloorSubtype;
use Illuminate\Support\Carbon;

/**
 * ADR 0040 / docs/domain/stock-recalculation.md "Backdating horizon" — decides
 * whether a proposed valuation-effective date is within the company's
 * configured horizon, composed with the ADR 0036 inventory-cutover boundary.
 * ADR 0040: "the strictest cutover, accounting-period, valuation-policy and
 * horizon boundary wins". The valuation-policy boundary is still the caller's
 * responsibility — that subsystem does not exist yet.
 *
 * Read-only: queries AccountingPeriod and InventoryCutover directly, the same
 * cross-module Eloquent read Company::class already performs for its own
 * fiscal_year_start_month guard (docs/architecture/code-structure.md "Reads
 * are direct Eloquent").
 */
final class BackdatingHorizonEvaluator
{
    public function evaluate(Company $company, Carbon $proposedValuationEffectiveDate): HorizonBoundary
    {
        $boundary = match ($company->backdating_horizon_mode) {
            BackdatingHorizonMode::PostingFloor => $this->evaluatePostingFloor($company, $proposedValuationEffectiveDate),
            BackdatingHorizonMode::PeriodLockOnly => $this->evaluatePeriodLockOnly($company, $proposedValuationEffectiveDate),
            BackdatingHorizonMode::CurrentAndPreviousPeriod => $this->evaluateCurrentAndPreviousPeriod($company, $proposedValuationEffectiveDate),
        };

        return $this->composeCutoverBoundary($company, $proposedValuationEffectiveDate, $boundary);
    }

    /**
     * Nothing may be valuation-effective before the opening state an inventory
     * cutover established. APPROVED is included alongside FINALISED because
     * CommitInventoryCutoverOpeningState posts that opening state while the
     * cutover is still APPROVED — waiting for FINALISED would leave the window
     * between commit and finalisation unguarded.
     */
    private function composeCutoverBoundary(Company $company, Carbon $proposed, HorizonBoundary $boundary): HorizonBoundary
    {
        $cutoverAt = InventoryCutover::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [InventoryCutoverStatus::Approved->value, InventoryCutoverStatus::Finalised->value])
            ->orderByDesc('cutover_at')
            ->value('cutover_at');

        if ($cutoverAt === null) {
            return $boundary;
        }

        // The cutover instant decides eligibility; permittedFloor stays a date
        // because that is the column it is snapshotted into, so a same-day
        // movement before the cutover instant is refused by withinHorizon
        // while the reported floor is that day. cutover_at casts to
        // CarbonImmutable, so it is converted rather than handed straight to
        // HorizonBoundary's mutable Carbon.
        $cutoverFloor = Carbon::instance($cutoverAt)->startOfDay();

        return new HorizonBoundary(
            withinHorizon: $boundary->withinHorizon && $proposed->greaterThanOrEqualTo($cutoverAt),
            permittedFloor: $boundary->permittedFloor === null
                ? $cutoverFloor
                : Carbon::instance($boundary->permittedFloor->max($cutoverFloor)),
            mode: $boundary->mode,
        );
    }

    private function evaluatePostingFloor(Company $company, Carbon $proposed): HorizonBoundary
    {
        // The DB shape CHECK (companies_backdating_posting_floor_shape_check)
        // guarantees fixed_date/rolling_days is set exactly when its own
        // subtype is selected, so POSTING_FLOOR mode always has one of the
        // two — the null arm below is an integrity-guard backstop, not an
        // expected path.
        $floor = match ($company->backdating_posting_floor_subtype) {
            PostingFloorSubtype::FixedDate => $company->backdating_posting_floor_fixed_date->copy()->startOfDay(),
            PostingFloorSubtype::RollingFormula => Carbon::now($company->timezone)
                ->startOfDay()->subDays($company->backdating_posting_floor_rolling_days),
            null => throw new DomainInvariantException(
                'POSTING_FLOOR mode requires a posting-floor subtype.', 'backdating_posting_floor_subtype',
            ),
        };

        return new HorizonBoundary(
            withinHorizon: $proposed->copy()->startOfDay()->greaterThanOrEqualTo($floor),
            permittedFloor: $floor,
            mode: $company->backdating_horizon_mode,
        );
    }

    private function evaluatePeriodLockOnly(Company $company, Carbon $proposed): HorizonBoundary
    {
        $period = $this->periodContaining($company, $proposed);

        return new HorizonBoundary(
            withinHorizon: $period !== null && $period->status === PeriodStatus::Open,
            permittedFloor: null,
            mode: $company->backdating_horizon_mode,
        );
    }

    private function evaluateCurrentAndPreviousPeriod(Company $company, Carbon $proposed): HorizonBoundary
    {
        $currentPeriod = $this->periodContaining($company, Carbon::now($company->timezone));
        if ($currentPeriod === null) {
            throw new DomainInvariantException('No accounting period covers the current date.', 'backdating_horizon_mode');
        }

        $previousPeriod = AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('starts_on', '<', $currentPeriod->starts_on)
            ->orderByDesc('starts_on')
            ->first();

        // A closed previous period tightens the floor to the current period's
        // own start — the strictest boundary wins within this mode too, not
        // just across cutover/period/policy/horizon.
        $floor = ($previousPeriod !== null && $previousPeriod->status === PeriodStatus::Open)
            ? $previousPeriod->starts_on
            : $currentPeriod->starts_on;

        return new HorizonBoundary(
            withinHorizon: $proposed->copy()->startOfDay()->greaterThanOrEqualTo($floor),
            permittedFloor: $floor->copy(),
            mode: $company->backdating_horizon_mode,
        );
    }

    private function periodContaining(Company $company, Carbon $date): ?AccountingPeriod
    {
        return AccountingPeriod::withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first();
    }
}

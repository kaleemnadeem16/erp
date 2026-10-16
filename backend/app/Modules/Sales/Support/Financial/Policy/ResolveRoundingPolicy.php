<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

use App\Modules\Sales\Models\RoundingPolicyVersion;
use App\Modules\Sales\Support\Fx\FinancialDate;
use App\Support\CompanyContext;

/**
 * The rounding policy in force for this company on a date (a pricing date, an issue date — the caller names it). History resolves: a superseded
 * version still answers for the dates it covered. A date before any activation gets the built-in default, which is what was in force. Internal: it checks
 * no permission, because every calculation needs the policy; changing it is the guarded Actions' business.
 */
final class ResolveRoundingPolicy
{
    public function __construct(private readonly CompanyContext $company) {}

    public function __invoke(string $date): RoundingPolicy
    {
        FinancialDate::assertCalendarDate($date);

        $versions = RoundingPolicyVersion::query()
            ->whereIn('status', [RoundingPolicyStatus::Active->value, RoundingPolicyStatus::Superseded->value])
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_end_exclusive')->orWhere('effective_end_exclusive', '>', $date))
            ->get();

        if ($versions->count() > 1) {
            throw RoundingPolicyRefusal::make(RoundingPolicyRefusal::ROUNDING_POLICY_AMBIGUOUS, 'More than one rounding policy version covers that date.', 'date');
        }

        return $versions->isEmpty() ? RoundingPolicy::builtInDefault($this->company->id()) : RoundingPolicy::fromVersion($versions->first());
    }
}

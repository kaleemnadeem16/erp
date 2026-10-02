<?php

declare(strict_types=1);

namespace App\Support\CompanyLocks;

use App\Models\Company;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Support\PeriodStatus;

/**
 * Why a company setting can no longer be changed, in one place: the model guard refuses with this text and the Company screen shows
 * the same text beside the read-only field, so the two cannot disagree (ADR 0024, ADR 0081). Null means the setting is free to change.
 */
final class CompanyLocks
{
    public function __construct(private readonly FinancialActivity $activity) {}

    /** The base currency is set once and changeable only before any financial record exists (ADR 0012, ADR 0024). */
    public function baseCurrency(Company $company): ?string
    {
        if ($company->getOriginal('base_currency_id') === null && $company->base_currency_id === null) {
            return null; // never set: setting it for the first time is always allowed
        }
        $existing = $this->activity->existing();

        return $existing === []
            ? null
            : 'The base currency can no longer be changed: '.implode(', ', $existing).' already exist in it, and every amount they hold is pinned in that currency.';
    }

    /** The fiscal year start month is changeable until the first accounting period is closed (ADR 0024). */
    public function fiscalYearStartMonth(Company $company): ?string
    {
        $closed = AccountingPeriod::withoutGlobalScopes()->where('company_id', $company->getKey())->where('status', PeriodStatus::Closed->value)->exists();

        return $closed ? 'The fiscal year start month can no longer be changed: an accounting period has been closed.' : null;
    }
}

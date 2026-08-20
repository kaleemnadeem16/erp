<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Support\PeriodStatus;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The tenant-isolation boundary itself — see docs/architecture/multi-tenancy.md.
 * Single row (id 1) until multi-tenancy is enabled; App\Support\CompanyContext is
 * the seam that changes then, not this model. base_currency_id/fiscal_year_start_month
 * implement docs/decisions/0024-onboarding-base-currency-and-fiscal-year.md.
 *
 * @property string $name
 * @property int|null $base_currency_id
 * @property int $fiscal_year_start_month
 */
class Company extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (Company $company): void {
            if (! $company->isDirty('fiscal_year_start_month')) {
                return;
            }

            // ADR 0024's lock condition is the first closed period. Permissioned
            // close/reopen transitions remain Phase 6; this guard protects the
            // company setting once the persisted accounting state says CLOSED.
            if (AccountingPeriod::withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('status', PeriodStatus::Closed->value)
                ->exists()) {
                throw new RuntimeException(
                    'Company fiscal_year_start_month is immutable after an accounting period is closed.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}

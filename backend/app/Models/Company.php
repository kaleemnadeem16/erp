<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}

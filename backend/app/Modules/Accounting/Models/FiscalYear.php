<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Model;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Created explicitly, not auto-generated from companies.fiscal_year_start_month
 * — see docs/domain/general-ledger.md "Fiscal years and periods". Overlap and
 * starts_on <= ends_on are enforced at the database level (see the creating
 * migration), not here — nothing to guard in the model beyond company scoping.
 *
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property PeriodStatus $status
 */
class FiscalYear extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => PeriodStatus::class,
        ];
    }

    /**
     * @return HasMany<AccountingPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }
}

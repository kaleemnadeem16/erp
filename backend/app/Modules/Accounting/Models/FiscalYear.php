<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Model;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Created explicitly, not auto-generated from companies.fiscal_year_start_month
 * — see docs/domain/general-ledger.md "Fiscal years and periods". Overlap and
 * starts_on <= ends_on are enforced at the database level (see the creating
 * migration). The saving guard prevents later date changes from excluding a
 * period already assigned to this fiscal year; containment must hold in both
 * directions over the lifetime of the records.
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

    protected static function booted(): void
    {
        static::saving(function (FiscalYear $fiscalYear): void {
            if (! $fiscalYear->exists || ! $fiscalYear->isDirty(['starts_on', 'ends_on'])) {
                return;
            }

            $startsOn = Carbon::parse($fiscalYear->starts_on);
            $endsOn = Carbon::parse($fiscalYear->ends_on);

            $hasExcludedPeriod = $fiscalYear->periods()
                ->where(function ($query) use ($startsOn, $endsOn): void {
                    $query->where('starts_on', '<', $startsOn->toDateString())
                        ->orWhere('ends_on', '>', $endsOn->toDateString());
                })
                ->exists();

            if ($hasExcludedPeriod) {
                throw new RuntimeException('FiscalYear dates cannot exclude an existing accounting period.');
            }
        });
    }

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

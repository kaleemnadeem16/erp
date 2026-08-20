<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Model;
use App\Modules\Accounting\Support\PeriodStatus;
use App\Support\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A period within a FiscalYear — see docs/domain/general-ledger.md "Fiscal years
 * and periods". Overlap and starts_on <= ends_on are enforced at the database
 * level (see the creating migration). What the database can't express — that
 * fiscal_year_id resolves to a fiscal year in the current company, and that this
 * period's range falls entirely inside that fiscal year's — is guarded here,
 * on save, the same shape as Category's parent guard: re-resolve the reference
 * through a scoped query rather than trusting a raw foreign key.
 *
 * @property int $fiscal_year_id
 * @property int $period_no
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property PeriodStatus $status
 */
class AccountingPeriod extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (AccountingPeriod $period): void {
            $period->guardFiscalYear();
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
     * @return BelongsTo<FiscalYear, $this>
     */
    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    private function guardFiscalYear(): void
    {
        if (! $this->isDirty(['fiscal_year_id', 'starts_on', 'ends_on'])) {
            return;
        }

        $fiscalYear = FiscalYear::query()->whereKey($this->fiscal_year_id)->first();

        if ($fiscalYear === null) {
            throw new RuntimeException(sprintf(
                'AccountingPeriod cannot be saved: fiscal_year_id %s does not resolve to a FiscalYear in the current company.',
                $this->fiscal_year_id,
            ));
        }

        if ($this->starts_on->lt($fiscalYear->starts_on) || $this->ends_on->gt($fiscalYear->ends_on)) {
            throw new RuntimeException(sprintf(
                'AccountingPeriod %s–%s must fall entirely within its fiscal year %s–%s.',
                $this->starts_on->toDateString(),
                $this->ends_on->toDateString(),
                $fiscalYear->starts_on->toDateString(),
                $fiscalYear->ends_on->toDateString(),
            ));
        }
    }
}

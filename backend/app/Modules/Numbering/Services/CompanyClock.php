<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Support\CompanyContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "What day is it for this company" — the company's time zone (ADR 0079 §8). That is the existing `companies.accounting_timezone`
 * (an IANA name, default UTC, set in Inventory settings): numbering reuses it rather than keeping a second time-zone column.
 */
final class CompanyClock
{
    public function __construct(private readonly CompanyContext $company) {}

    /** The IANA time-zone name; UTC until the company sets its own. */
    public function timezone(): string
    {
        $zone = DB::table('companies')->where('id', $this->company->id())->value('accounting_timezone');

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /** True while the company is still on the UTC default, where a date near midnight may fall on the wrong day for the business. */
    public function isDefault(): bool
    {
        return $this->timezone() === 'UTC';
    }

    public function local(CarbonImmutable $instant): CarbonImmutable
    {
        return $instant->setTimezone($this->timezone());
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Services;

use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;

/**
 * "What day is it for this company" — the company's time zone (ADR 0079 §8). That is the central `companies.timezone`
 * (an IANA name, default UTC, set in Settings → Company, ADR 0081), read through CurrentCompany like every other module.
 */
final class CompanyClock
{
    public function __construct(private readonly CurrentCompany $company) {}

    /** The IANA time-zone name; UTC until the company sets its own. */
    public function timezone(): string
    {
        return $this->company->timezone();
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

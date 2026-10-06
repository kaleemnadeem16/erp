<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;

/**
 * The few company-wide facts other modules read — the time zone, the short code, the name — through one small accessor instead of
 * each reaching into the companies table (ADR 0081). It always reads the current row, so a change on the Company screen is seen at
 * once; it caches nothing.
 */
class CurrentCompany
{
    public function __construct(private readonly CompanyContext $context) {}

    private function row(): Company
    {
        return Company::query()->findOrFail($this->context->id());
    }

    public function name(): string
    {
        return $this->row()->name;
    }

    /** The stable short code used in document numbers; null until the company sets one. */
    public function code(): ?string
    {
        return $this->row()->code;
    }

    /** The code used to label frozen base-currency evidence; null before currency setup. */
    public function baseCurrencyCode(): ?string
    {
        return $this->row()->baseCurrency?->code;
    }

    /** The IANA time zone of the company's books and business day (default UTC). */
    public function timezone(): string
    {
        $zone = $this->row()->timezone;

        return $zone !== '' ? $zone : 'UTC';
    }

    /** True while the company has not chosen a zone of its own and is still on the UTC default. */
    public function timezoneIsDefault(): bool
    {
        return $this->timezone() === 'UTC';
    }
}

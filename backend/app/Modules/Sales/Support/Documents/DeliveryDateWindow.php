<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Documents;

use App\Models\Company;
use App\Support\CompanyContext;
use Carbon\CarbonImmutable;

/**
 * How far ahead a manual delivery may be dated (ADR 0181): up to tomorrow on the COMPANY's calendar, so a person dating "today" or the next morning is never refused or
 * allowed a day late because the server or database clock sits in another zone. Never the database session date, never the UTC date. The database trigger applies the same rule.
 */
final class DeliveryDateWindow
{
    public function __construct(private readonly CompanyContext $company) {}

    /** The latest accepted `delivered_on`, as Y-m-d. */
    public function latest(): string
    {
        $timezone = (string) Company::query()->whereKey($this->company->id())->value('timezone');

        return CarbonImmutable::now($timezone)->addDay()->format('Y-m-d');
    }
}

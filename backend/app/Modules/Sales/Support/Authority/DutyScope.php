<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

/**
 * The record scopes a duty actually supports. Initial Sales scope is explicit company-wide only: a person limited to "my units" for the duty is refused
 * (403) rather than shown a filtered subset, until every surface of that duty genuinely integrates restricted visibility (ADR 0106, 0138).
 */
enum DutyScope: string
{
    case CompanyWide = 'COMPANY_WIDE';
}

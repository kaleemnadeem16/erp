<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

/** The variables the engine computes itself (ADR 0079 §4). Document variables and company constants are not listed here. */
final class VariableCatalog
{
    /** @var array<string, array{label: string, description: string, kind: string}> */
    public const ENGINE = [
        'YYYY' => ['label' => 'Year (4 digits)', 'description' => 'The year the document became official, in the company time zone.', 'kind' => 'date'],
        'YY' => ['label' => 'Year (2 digits)', 'description' => 'The last two digits of that year.', 'kind' => 'date'],
        'MM' => ['label' => 'Month', 'description' => 'The month, 01 to 12.', 'kind' => 'date'],
        'DD' => ['label' => 'Day', 'description' => 'The day of the month, 01 to 31.', 'kind' => 'date'],
        'FY' => ['label' => 'Fiscal year', 'description' => 'The company fiscal year: 2026, or 2025-26 when it spans two calendar years. {FY:START} and {FY:END} give one year.', 'kind' => 'fiscal'],
        'COMPANY' => ['label' => 'Company code', 'description' => 'The company\'s short code from Settings → Company. It never comes from the editable company name; numbers already issued keep the code they were issued with.', 'kind' => 'company'],
        'FP' => ['label' => 'Fiscal period', 'description' => 'The fiscal period number, two digits.', 'kind' => 'fiscal'],
    ];

    public static function isEngine(string $key): bool
    {
        return isset(self::ENGINE[$key]);
    }

    public static function needsDate(string $key): bool
    {
        return isset(self::ENGINE[$key]) && self::ENGINE[$key]['kind'] !== 'company';
    }

    public static function needsFiscalCalendar(string $key): bool
    {
        return (self::ENGINE[$key]['kind'] ?? null) === 'fiscal';
    }
}

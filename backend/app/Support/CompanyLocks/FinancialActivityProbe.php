<?php

declare(strict_types=1);

namespace App\Support\CompanyLocks;

/**
 * One kind of financial activity that makes the company's base currency unsafe to change. Each module that creates monetary
 * records registers a probe for them; the company's base currency locks as soon as any probe says its records exist.
 */
interface FinancialActivityProbe
{
    /** What exists, in words a person can act on: "purchase orders". */
    public function label(): string;

    public function exists(): bool;
}

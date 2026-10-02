<?php

declare(strict_types=1);

namespace App\Support\CompanyLocks;

use Closure;

/** A probe defined by a label and a closure — enough for "do any rows of this model exist". */
final class ExistsProbe implements FinancialActivityProbe
{
    /** @param  Closure(): bool  $exists */
    public function __construct(private readonly string $label, private readonly Closure $exists) {}

    public function label(): string
    {
        return $this->label;
    }

    public function exists(): bool
    {
        return ($this->exists)();
    }
}

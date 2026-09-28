<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * How one ordered criterion of the effective preset was treated for one item:
 * `applied` false means the evidence it needs does not exist in the current
 * tracking mode (or for these balances), with the reason in `note`.
 */
final class AllocationCriterionReport
{
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly bool $applied,
        public readonly string $note,
    ) {}
}

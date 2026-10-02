<?php

declare(strict_types=1);

namespace App\Modules\Numbering\Support;

use Carbon\CarbonImmutable;

/** Where a local date falls in the company's fiscal calendar. */
final class FiscalPosition
{
    public function __construct(
        public readonly string $yearKey,
        public readonly CarbonImmutable $yearStart,
        public readonly CarbonImmutable $yearEnd,
        public readonly string $periodKey,
        public readonly int $periodNumber,
    ) {}
}

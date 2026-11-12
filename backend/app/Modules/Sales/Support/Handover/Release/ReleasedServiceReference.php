<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/** Safe, stable references to the Job a release produced. No source detail, money or evidence. */
final readonly class ReleasedServiceReference
{
    public function __construct(public int $jobId, public string $jobCode) {}
}

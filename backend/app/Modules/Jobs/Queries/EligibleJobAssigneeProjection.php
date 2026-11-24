<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Queries;

/** Minimal assignment choice; no member model or unrelated personal field escapes the guarded read. */
final readonly class EligibleJobAssigneeProjection
{
    public function __construct(public int $id, public string $name) {}
}

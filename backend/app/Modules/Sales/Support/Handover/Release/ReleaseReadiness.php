<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/** The evaluated release state of one prepared stage. `blockers` are stable codes; cost is reported separately from whether it is required. */
final readonly class ReleaseReadiness
{
    /**
     * @param  list<string>  $blockers
     * @param  list<array{fact:string,owner:string,action:string}>  $nextActions
     * @param  array<string,mixed>  $applicability
     * @param  array<string,mixed>  $relied  the source generations and identities this evaluation relied on
     */
    public function __construct(
        public array $blockers,
        public array $nextActions,
        public CostRequirement $cost,
        public array $applicability,
        public array $relied,
        public string $fingerprint,
    ) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}

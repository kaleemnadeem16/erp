<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Controls;

/**
 * What a cost-blind reader may see of an evaluation: the overall and per-control outcome and whether issue is blocked, by opaque control reference. No
 * cost, margin, threshold, reference price, reduction, FX rate, reason detail, control kind or fingerprint is here — a hash of protected operands would
 * let a reader test guesses against it, so none is exposed. Omission is not zero: UNEVALUABLE is stated as such.
 */
final readonly class NetControlOutcomeView
{
    /** @param  list<array{ref: string, outcome: string, blocks_issue: bool}>  $controls */
    public function __construct(public string $outcome, public bool $blocksIssue, public bool $requiresReview, public array $controls) {}

    /** @return array{outcome: string, blocks_issue: bool, requires_review: bool, controls: list<array{ref: string, outcome: string, blocks_issue: bool}>} */
    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'blocks_issue' => $this->blocksIssue, 'requires_review' => $this->requiresReview, 'controls' => $this->controls];
    }
}

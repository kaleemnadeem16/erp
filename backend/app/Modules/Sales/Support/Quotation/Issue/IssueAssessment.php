<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Issue;

/**
 * Everything the issue assessor found, kept together so the same facts that decide "ready" are the ones the issuer freezes. `preview`, `tax` and `controls` are null when the
 * header was too incomplete to compute them.
 */
final readonly class IssueAssessment
{
    /**
     * @param  list<IssueBlocker>  $blockers
     * @param  array<string, mixed>|null  $preview
     */
    public function __construct(public array $blockers, public ?array $preview, public ?QuotationTaxOutcome $tax, public ?QuotationControlsOutcome $controls) {}

    public function ready(): bool
    {
        return $this->blockers === [];
    }
}

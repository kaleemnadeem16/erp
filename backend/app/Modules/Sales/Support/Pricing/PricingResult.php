<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing;

use App\Modules\Sales\Support\Financial\FinancialResult;
use App\Modules\Sales\Support\Financial\SettledAmount;
use Brick\Math\BigDecimal;

/**
 * The internal result of resolving one line: the chosen price, the exact computed and settled line amount, and the source with its cost certainty.
 * It is evidence for later slices, not an issue-eligibility, approval or quotation. Cost is internal: `forReader(false)` omits it (omitted, not zero).
 */
final readonly class PricingResult
{
    public function __construct(
        public ResolvedSource $source,
        public PriceCandidate $price,
        public BigDecimal $quantity,
        public SettledAmount $line,
        public ?FinancialResult $cost,
        public bool $costWithheld = false,
    ) {}

    public function forReader(bool $costVisible): self
    {
        if ($costVisible) {
            return $this;
        }
        $source = new ResolvedSource($this->source->kind, $this->source->sourceId, $this->source->label, $this->source->uomId, $this->source->quantity,
            FinancialResult::unknown([['code' => 'COST_NOT_DISCLOSED']]));

        return new self($source, $this->price, $this->quantity, $this->line, null, true);
    }
}

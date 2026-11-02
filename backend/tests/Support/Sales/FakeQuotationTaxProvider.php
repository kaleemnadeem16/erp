<?php

namespace Tests\Support\Sales;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Quotation\Issue\IssueBlocker;
use App\Modules\Sales\Support\Quotation\Issue\QuotationLineTax;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxOutcome;
use App\Modules\Sales\Support\Quotation\Issue\QuotationTaxProvider;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A test double for the Tax seam: a flat percentage on every line's settled net, settled to two places. It proves how Sales reacts to a complete or unknown tax outcome;
 * it does NOT certify the FR3 adapter, and it is never bound outside tests.
 */
final class FakeQuotationTaxProvider implements QuotationTaxProvider
{
    /** @var list<array<int, string|null>> every call's nets, so a test can see what Sales passed in */
    public array $calls = [];

    /** @param  list<IssueBlocker>  $blockers  non-empty makes the whole outcome UNKNOWN */
    public function __construct(public string $percentage = '10', public array $blockers = [], public int $generation = 7) {}

    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationTaxOutcome
    {
        $this->calls[] = $settledNets;
        if ($this->blockers !== []) {
            return QuotationTaxOutcome::unknown($this->blockers);
        }
        $lines = [];
        $blockers = [];
        foreach ($settledNets as $lineId => $net) {
            if ($net === null) {
                $blockers[] = new IssueBlocker('TAX_NET_UNKNOWN', IssueBlocker::TAX, $lineId);

                continue;
            }
            $tax = BigDecimal::of($net)->multipliedBy($this->percentage)->dividedBy(100, 2, RoundingMode::HALF_UP)->toScale(4);
            $gross = BigDecimal::of($net)->plus($tax)->toScale(4);
            $lines[$lineId] = new QuotationLineTax($lineId, 'TAXABLE', (string) $tax, (string) $gross, hash('sha256', "line:{$lineId}:{$tax}"));
        }
        if ($blockers !== [] || $lines === []) {
            return QuotationTaxOutcome::unknown($blockers === [] ? [new IssueBlocker('TAX_NOT_DETERMINED', IssueBlocker::TAX)] : $blockers);
        }

        return QuotationTaxOutcome::complete($lines, 'FR3.1', $this->generation, hash('sha256', json_encode(array_map(fn (QuotationLineTax $l): string => $l->evidenceReference, $lines), JSON_THROW_ON_ERROR)));
    }
}

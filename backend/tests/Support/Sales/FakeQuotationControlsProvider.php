<?php

namespace Tests\Support\Sales;

use App\Modules\Sales\Models\QuotationRevision;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\Quotation\Issue\IssueBlocker;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsOutcome;
use App\Modules\Sales\Support\Quotation\Issue\QuotationControlsProvider;

/** A test double for the controls seam: PASS or WARN, or the blockers it was given. Never bound outside tests. */
final class FakeQuotationControlsProvider implements QuotationControlsProvider
{
    /** @param  list<IssueBlocker>  $blockers */
    public function __construct(public string $outcome = 'PASS', public array $blockers = []) {}

    public function assess(SalesActorContext $actor, QuotationRevision $revision, array $settledNets): QuotationControlsOutcome
    {
        return $this->blockers === [] ? QuotationControlsOutcome::passed($this->outcome, hash('sha256', 'controls:'.$revision->id.':'.$this->outcome)) : QuotationControlsOutcome::blocked($this->blockers);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\QuotationIssuedLine;
use App\Modules\Sales\Models\QuotationRevision;

/** @internal Locked subject; contains no mutable eligibility judgement. */
final readonly class AcceptanceSubject
{
    public function __construct(public CustomerDecisionCase $case, public CustomerDecisionComponent $component, public QuotationRevision $revision, public QuotationIssuedLine $line) {}
}

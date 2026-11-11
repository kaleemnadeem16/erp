<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover;

use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\QuotationIssuedLine;

/** @internal Locked genuine accepted scope, not an observation or mutable quotation flag. */
final readonly class HandoverSubject
{
    public function __construct(public CustomerAcceptance $acceptance, public CustomerAcceptanceLine $acceptanceLine, public QuotationIssuedLine $line, public CustomerDecisionCase $case) {}
}

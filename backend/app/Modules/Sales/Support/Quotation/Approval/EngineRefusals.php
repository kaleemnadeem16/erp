<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation\Approval;

use App\Exceptions\DomainInvariantException;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;

/** Gives the approval engine's plain refusals the quotation reason codes the API documents; the engine's own message is kept (it names no amount or limit). */
final class EngineRefusals
{
    public static function translate(DomainInvariantException $refusal): DomainInvariantException
    {
        if ($refusal->reasonCode !== null) {
            return $refusal;
        }
        $code = match ($refusal->field) {
            'decided_by' => QuotationRefusal::APPROVAL_NOT_ELIGIBLE,
            'status' => QuotationRefusal::APPROVAL_NOT_PENDING,
            'approval' => QuotationRefusal::APPROVAL_STALE,
            'reason' => QuotationRefusal::REASON_REQUIRED,
            'approval_policy' => QuotationRefusal::APPROVAL_NOT_CONFIGURED,
            'submitted_by' => QuotationRefusal::APPROVAL_NOT_ELIGIBLE,
            default => QuotationRefusal::APPROVAL_NOT_ELIGIBLE,
        };

        return QuotationRefusal::make($code, $refusal->getMessage(), $refusal->field);
    }
}

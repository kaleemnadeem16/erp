<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

use App\Exceptions\DomainInvariantException;

/** Stable refusal codes of the quotation draft Actions (QL1a). Pricing, FX and rounding refusals keep their own kernel codes and pass through unchanged. */
final class QuotationRefusal
{
    public const QUOTATION_NOT_FOUND = 'QUOTATION_NOT_FOUND';

    public const QUOTATION_NOT_EDITABLE = 'QUOTATION_NOT_EDITABLE';

    public const QUOTATION_DRAFT_STALE = 'QUOTATION_DRAFT_STALE';

    public const QUOTATION_DRAFT_CHANGED = 'QUOTATION_DRAFT_CHANGED';

    public const REQUEST_KEY_REQUIRED = 'REQUEST_KEY_REQUIRED';

    public const REQUEST_KEY_REUSED = 'REQUEST_KEY_REUSED';

    public const INVALID_INPUT = 'INVALID_INPUT';

    public const NOTHING_TO_CHANGE = 'NOTHING_TO_CHANGE';

    public const LINE_NOT_FOUND = 'LINE_NOT_FOUND';

    public const SOURCE_KIND_NOT_AUTHORISED = 'SOURCE_KIND_NOT_AUTHORISED';

    public const CHOICE_BASELINE_CONFLICT = 'CHOICE_BASELINE_CONFLICT';

    public const SCOPE_ALREADY_ON_REVISION = 'SCOPE_ALREADY_ON_REVISION';

    public const PRICING_DATE_REQUIRED = 'PRICING_DATE_REQUIRED';

    public const CURRENCY_LOCKED_BY_PRICES = 'CURRENCY_LOCKED_BY_PRICES';

    public const RECIPIENT_REQUIRED = 'RECIPIENT_REQUIRED';

    public const OPPORTUNITY_NOT_FOUND = 'OPPORTUNITY_NOT_FOUND';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}

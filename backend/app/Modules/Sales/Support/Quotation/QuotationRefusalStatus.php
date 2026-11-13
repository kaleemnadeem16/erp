<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Quotation;

/**
 * The HTTP status of every quotation draft refusal on the write adapters (QL1b-2, docs/architecture/api-conventions.md). A business rule or state that refuses the
 * operation is 422 with its `reason_code` (stale evidence included: a moved input generation is `QUOTATION_DRAFT_STALE`, not a 409); the only 409 is a request key
 * that is already bound to a different request; a quotation that is not found is 404 and says nothing about whether it exists elsewhere. Every code is listed so a
 * new refusal cannot ship without an explicit decision (QuotationRefusalStatusTest). Pricing, FX and rounding kernel codes pass through as 422.
 */
final class QuotationRefusalStatus
{
    public const NOT_FOUND = 404;

    public const CONFLICT = 409;

    public const UNPROCESSABLE = 422;

    /** @var array<string, int> */
    public const MAP = [
        QuotationRefusal::QUOTATION_NOT_FOUND => self::NOT_FOUND,
        QuotationRefusal::REQUEST_KEY_REUSED => self::CONFLICT,
        QuotationRefusal::QUOTATION_NOT_EDITABLE => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_ALREADY_ISSUED => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_NOT_ISSUABLE => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_NOT_APPLICABLE => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_VERSION_NOT_ACTIVE => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_BLOCKED => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_REASON_REQUIRED => self::UNPROCESSABLE,
        QuotationRefusal::STOCK_CHOICE_NOT_ADOPTABLE => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_NOT_FOUND => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_APPROVAL_POLICY_MISSING => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_NOTHING_TO_APPROVE => self::UNPROCESSABLE,
        QuotationRefusal::MATERIAL_PLAN_STALE => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_NOT_AVAILABLE => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_PREDECESSOR_INVALID => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_SUCCESSOR_EXISTS => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_DRAFT_STALE => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_DRAFT_CHANGED => self::UNPROCESSABLE,
        QuotationRefusal::REQUEST_KEY_REQUIRED => self::UNPROCESSABLE,
        QuotationRefusal::INVALID_INPUT => self::UNPROCESSABLE,
        QuotationRefusal::NOTHING_TO_CHANGE => self::UNPROCESSABLE,
        QuotationRefusal::LINE_NOT_FOUND => self::UNPROCESSABLE,
        QuotationRefusal::SOURCE_KIND_NOT_AUTHORISED => self::UNPROCESSABLE,
        QuotationRefusal::CHOICE_BASELINE_CONFLICT => self::UNPROCESSABLE,
        QuotationRefusal::SCOPE_ALREADY_ON_REVISION => self::UNPROCESSABLE,
        QuotationRefusal::PRICING_DATE_REQUIRED => self::UNPROCESSABLE,
        QuotationRefusal::CURRENCY_LOCKED_BY_PRICES => self::UNPROCESSABLE,
        QuotationRefusal::RECIPIENT_REQUIRED => self::UNPROCESSABLE,
        QuotationRefusal::OPPORTUNITY_NOT_FOUND => self::UNPROCESSABLE,
        QuotationRefusal::OPPORTUNITY_NOT_OPEN => self::UNPROCESSABLE,
        QuotationRefusal::OPPORTUNITY_PARTY_MISMATCH => self::UNPROCESSABLE,
        // QA1a ownership refusals: reachable only from the internal ownership Actions today (no route), each a business rule, hence 422 if a surface ever exposes them.
        QuotationRefusal::QUOTATION_OWNERSHIP_STALE => self::UNPROCESSABLE,
        QuotationRefusal::QUOTATION_OWNERSHIP_UNRESOLVED => self::UNPROCESSABLE,
        QuotationRefusal::ACCESS_UNIT_NOT_FOUND => self::UNPROCESSABLE,
        QuotationRefusal::BASELINE_NOT_APPLICABLE => self::UNPROCESSABLE,
        QuotationRefusal::BASELINE_CONFLICT => self::UNPROCESSABLE,
        QuotationRefusal::REASON_REQUIRED => self::UNPROCESSABLE,
        QuotationRefusal::DISCOUNT_EXCEEDS_BASE => self::UNPROCESSABLE,
        QuotationRefusal::DISCOUNT_PRECISION_EXCEEDED => self::UNPROCESSABLE,
        QuotationRefusal::OVERRIDE_NOT_ALLOWED => self::UNPROCESSABLE,
        QuotationRefusal::OVERRIDE_BASIS_CHANGED => self::UNPROCESSABLE,
        QuotationRefusal::SHARE_RECIPIENT_INELIGIBLE => self::UNPROCESSABLE,
        QuotationRefusal::SHARE_OVERLAP => self::UNPROCESSABLE,
        QuotationRefusal::TAX_DECLARATION_NOT_ALLOWED => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_NOT_NEEDED => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_NOT_CONFIGURED => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_ALREADY_GRANTED => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_REJECTED => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_NOT_PENDING => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_STALE => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_SELF_APPROVAL => self::UNPROCESSABLE,
        QuotationRefusal::APPROVAL_NOT_ELIGIBLE => self::UNPROCESSABLE,
    ];

    public static function for(?string $reasonCode): int
    {
        return self::MAP[$reasonCode ?? ''] ?? self::UNPROCESSABLE;
    }
}

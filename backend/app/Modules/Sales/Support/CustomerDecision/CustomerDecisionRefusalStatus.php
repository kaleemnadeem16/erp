<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

/**
 * The HTTP status of every customer-decision intake refusal (docs/architecture/api-conventions.md): a case that is not there is 404 and says nothing more; a request key bound to a
 * different request, a case that moved since the caller read it and an intake identity that already exists are 409 (the caller must reload or choose differently); every other
 * business rule is 422. Every code is listed so a new refusal cannot ship without an explicit decision (CustomerDecisionApiTest).
 */
final class CustomerDecisionRefusalStatus
{
    public const NOT_FOUND = 404;

    public const CONFLICT = 409;

    public const UNPROCESSABLE = 422;

    /** @var array<string, int> */
    public const MAP = [
        CustomerDecisionRefusal::CASE_NOT_FOUND => self::NOT_FOUND,
        CustomerDecisionRefusal::REQUEST_KEY_REUSED => self::CONFLICT,
        CustomerDecisionRefusal::CASE_STALE => self::CONFLICT,
        CustomerDecisionRefusal::INTAKE_IDENTITY_EXISTS => self::CONFLICT,
        CustomerDecisionRefusal::REQUEST_KEY_REQUIRED => self::UNPROCESSABLE,
        CustomerDecisionRefusal::INVALID_INPUT => self::UNPROCESSABLE,
        CustomerDecisionRefusal::EVIDENCE_PREDECESSOR_INVALID => self::UNPROCESSABLE,
    ];

    public static function for(?string $reasonCode): int
    {
        return self::MAP[$reasonCode ?? ''] ?? self::UNPROCESSABLE;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Exceptions\DomainInvariantException;

/** Refusals of the customer-decision intake Actions: fixed text, never a figure, an identity of another case or any evidence content. */
final class CustomerDecisionRefusal
{
    public const REQUEST_KEY_REQUIRED = 'REQUEST_KEY_REQUIRED';

    public const REQUEST_KEY_REUSED = 'REQUEST_KEY_REUSED';

    public const INVALID_INPUT = 'INVALID_INPUT';

    public const CASE_NOT_FOUND = 'DECISION_CASE_NOT_FOUND';

    public const CASE_STALE = 'DECISION_CASE_STALE';

    public const INTAKE_IDENTITY_EXISTS = 'INTAKE_IDENTITY_EXISTS';

    public const EVIDENCE_PREDECESSOR_INVALID = 'EVIDENCE_PREDECESSOR_INVALID';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Lifecycle;

use App\Exceptions\DomainInvariantException;

/**
 * The stable internal refusal reasons of the Job lifecycle Actions. A Job that is absent, in another company or not reachable gives the one JOB_NOT_FOUND, saying
 * nothing about whether it exists elsewhere. No HTTP status contract is defined here: there is no route.
 */
final class JobLifecycleRefusal
{
    public const JOB_NOT_FOUND = 'JOB_NOT_FOUND';

    public const REQUEST_KEY_REQUIRED = 'REQUEST_KEY_REQUIRED';

    public const REQUEST_KEY_REUSED = 'REQUEST_KEY_REUSED';

    public const COMMAND_CONFLICT = 'COMMAND_CONFLICT';

    public const INVALID_INPUT = 'INVALID_INPUT';

    public const JOB_STATE_NOT_ALLOWED = 'JOB_STATE_NOT_ALLOWED';

    public const STALE_GENERATION = 'STALE_GENERATION';

    public const STALE_VERSION_VECTOR = 'STALE_VERSION_VECTOR';

    public const STALE_SOURCE_CONTEXT = 'STALE_SOURCE_CONTEXT';

    public const JOB_HELD = 'JOB_HELD';

    public const RESPONSIBILITY_REQUIRED = 'RESPONSIBILITY_REQUIRED';

    public const ASSIGNEE_NOT_ELIGIBLE = 'ASSIGNEE_NOT_ELIGIBLE';

    public const HOLD_NOT_FOUND = 'HOLD_NOT_FOUND';

    public const HOLD_ALREADY_RESOLVED = 'HOLD_ALREADY_RESOLVED';

    public const SOURCE_CONTEXT_UNAVAILABLE = 'SOURCE_CONTEXT_UNAVAILABLE';

    public const NOT_READY_TO_START = 'NOT_READY_TO_START';

    public const CONCURRENT_CHANGE = 'CONCURRENT_CHANGE';

    public static function make(string $code, string $message, ?string $field = null): DomainInvariantException
    {
        return new DomainInvariantException($message, $field, $code);
    }
}

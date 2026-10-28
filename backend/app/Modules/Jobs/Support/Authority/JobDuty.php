<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support\Authority;

use InvalidArgumentException;

/**
 * One Jobs duty: its exact name, whether it is live, what it does, the least it may reveal, and what must exist before it is. Every Jobs duty is new, company-wide
 * (limited scopes are unsupported and refuse) and explicit-grant-only: nothing here is ever part of an automatic "every registered capability" grant.
 */
final readonly class JobDuty
{
    public function __construct(
        public string $name,
        public JobDutyStatus $status,
        public string $operation,
        public string $projection,
        public string $deliveryGate,
    ) {
        // Exact names only: lowercase dotted words under the `job` namespace; an underscore is allowed inside a word (job.cancel_unused is Task B's exact spelling).
        if (preg_match('/^job(?:\.[a-z][a-z0-9_]*)+$/', $name) !== 1) {
            throw new InvalidArgumentException("[{$name}] is not an exact Jobs duty name (no wildcards, no other module's namespace).");
        }
        foreach ([$operation, $projection, $deliveryGate] as $text) {
            if (trim($text) === '') {
                throw new InvalidArgumentException("Jobs duty [{$name}] needs an operation, a minimum projection and a delivery gate.");
            }
        }
    }

    public function isActive(): bool
    {
        return $this->status === JobDutyStatus::Active;
    }

    /** No Jobs duty is ever granted by an automatic "every registered capability" path; a holder is always explicit and reviewed. */
    public function automaticallyGrantable(): bool
    {
        return false;
    }
}

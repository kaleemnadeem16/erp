<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use InvalidArgumentException;

/** Whether cost evidence is mandatory, whether it is evidenced, and the explicit status. `status` is UNASSESSED, UNKNOWN or EVIDENCED; required cost is only ever satisfied by EVIDENCED. */
final readonly class CostRequirement
{
    public function __construct(public bool $required, public string $status, public string $policyVersion)
    {
        if (! in_array($status, ['UNASSESSED', 'UNKNOWN', 'EVIDENCED'], true) || trim($policyVersion) === '') {
            throw new InvalidArgumentException('A cost requirement needs a known status and a policy version.');
        }
    }

    public function satisfied(): bool
    {
        return ! $this->required || $this->status === 'EVIDENCED';
    }
}

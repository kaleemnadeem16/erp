<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Modules\Sales\Support\Handover\HandoverSubject;

/** Default: no mandatory commercial cost control is delivered, so cost is explicitly UNASSESSED and not required. It is never a fake zero or a completeness claim. */
final class NoMandatoryReleaseCostPolicy implements ReleaseCostPolicy
{
    public const VERSION = 'RELEASE_COST_POLICY.1';

    public function requirementFor(HandoverSubject $subject): CostRequirement
    {
        return new CostRequirement(false, 'UNASSESSED', self::VERSION);
    }
}

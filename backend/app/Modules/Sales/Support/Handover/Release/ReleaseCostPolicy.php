<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Modules\Sales\Support\Handover\HandoverSubject;

/**
 * The applicable commercial/release control policy for cost evidence on one accepted service. Cost status and whether cost is REQUIRED are separate facts: a
 * missing optional cost never blocks a completion-only release (it stays UNASSESSED/UNKNOWN, never zero), while a mandatory commercial control that needs cost
 * evidence blocks until a supported source supplies it. The default binding finds no mandatory control; a later, reviewed policy replaces it.
 */
interface ReleaseCostPolicy
{
    public function requirementFor(HandoverSubject $subject): CostRequirement;
}

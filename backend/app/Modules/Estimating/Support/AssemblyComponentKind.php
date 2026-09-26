<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Four of the standard five-part estimating breakdown — see
 * docs/domain/assemblies.md "Schema". OVERHEAD is deliberately not a case
 * here: it is computed from the resolved overhead policy at roll-up time,
 * never a stored component row (ADR 0049). `job_cost_entries.kind` (Phase 5
 * actuals) still has all five, including OVERHEAD, for the actuals side of
 * variance reporting — see assemblies.md's schema note reconciling the two.
 */
enum AssemblyComponentKind: string
{
    case Material = 'MATERIAL';
    case Labour = 'LABOUR';
    case Plant = 'PLANT';
    case Subcontract = 'SUBCONTRACT';
}

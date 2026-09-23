<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * DRAFT is the only editable state — lines, notes and dates may all change
 * freely. ACTIVE is the one currently-live recipe for its formulation.
 * SUPERSEDED is historical, kept forever. See
 * docs/domain/formulations.md "Versioning and immutability" and
 * docs/phases/phase-3-estimating-core.md for this phase's DRAFT-vs-frozen
 * boundary.
 */
enum FormulationVersionStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
}

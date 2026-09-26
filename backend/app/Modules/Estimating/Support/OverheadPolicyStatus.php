<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Mirrors FormulationVersionStatus — DRAFT is the only editable state.
 * ACTIVE is the one currently-resolving policy for its scope; SUPERSEDED is
 * historical, kept forever. Phase 3's concrete reading of "immutable once
 * referenced by a snapshotted cost roll-up" (docs/domain/assemblies.md
 * "Overhead allocation") ahead of Phase 4 actually issuing documents.
 */
enum OverheadPolicyStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
}

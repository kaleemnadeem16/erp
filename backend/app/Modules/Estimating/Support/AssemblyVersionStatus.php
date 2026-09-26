<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Mirrors FormulationVersionStatus exactly — DRAFT is the only editable
 * state; ACTIVE is the one currently-costed version for its assembly;
 * SUPERSEDED is historical, kept forever. See docs/domain/assemblies.md
 * "Versioning".
 */
enum AssemblyVersionStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
}

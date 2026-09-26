<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Mirrors FormulationStatus — DRAFT: no assembly_version has ever been
 * activated yet. ACTIVE: ActivateAssemblyVersion has activated at least one
 * version (mirrors ActivateFormulationVersion marking Formulation ACTIVE).
 * ARCHIVED: ArchiveAssembly.
 */
enum AssemblyStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}

<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * Mirrors FormulationStatus — DRAFT: not yet activated (no assembly_versions
 * exist until Step 3, so every assembly stays DRAFT through this slice).
 * ACTIVE and ARCHIVED gain their real transition mechanisms once versions
 * and components land.
 */
enum AssemblyStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}

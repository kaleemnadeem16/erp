<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * The formulation header's own lifecycle — distinct from
 * FormulationVersionStatus, which tracks which specific recipe content is
 * currently live. DRAFT: created but never activated, not resolvable.
 * ACTIVE: has (or has had) an activated version. ARCHIVED: retired, never
 * resolvable again even if an ACTIVE version still exists underneath.
 */
enum FormulationStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Archived = 'ARCHIVED';
}

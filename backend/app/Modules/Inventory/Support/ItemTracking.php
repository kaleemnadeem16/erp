<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * How individual units of a stocked item are traced — see docs/domain/items.md.
 * NONE is the default; BATCH/SERIAL matter once lot- or serial-level
 * traceability is needed, from Phase 2 onward.
 */
enum ItemTracking: string
{
    case None = 'NONE';
    case Batch = 'BATCH';
    case Serial = 'SERIAL';
}

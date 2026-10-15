<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Fx;

enum FxMode: string
{
    /** Only a rate dated exactly on the requested date (maximum age zero) — the default. */
    case ExactDate = 'EXACT_DATE';

    /** The latest rate on or before the requested date, no older than the policy's maximum age. Never a later one. */
    case BoundedPrior = 'BOUNDED_PRIOR';
}

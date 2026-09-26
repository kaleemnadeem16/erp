<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/**
 * MARGIN and MARKUP are not interchangeable — see docs/domain/assemblies.md
 * "Margin is not markup". Stored explicitly and never inferred; the roll-up
 * that actually uses this arrives in Step 3.
 */
enum MarginType: string
{
    case Margin = 'MARGIN';
    case Markup = 'MARKUP';
}

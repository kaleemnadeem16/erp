<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover;

use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;

/** Preparation support only. A registry entry is not a delivered Job receiver or permission to release. */
final class HandoverSourceRegistry
{
    public const VERSION = 'SERVICE_PREPARATION.1';

    public function requirePreparation(string $sourceKind, string $path): void
    {
        if ($sourceKind !== 'SERVICE_ITEM' || $path !== 'SERVICE_JOB') {
            throw R::make(R::HANDOVER_PATH_UNSUPPORTED, 'Only full accepted service Item scope has a preparation adapter.', 'path');
        }
    }
}

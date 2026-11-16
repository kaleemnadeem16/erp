<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

/** The outcome of checking a Job's Sales fulfilment receipt against its completion and the agreed work it was released with. */
final class FulfilmentState
{
    public const OK = 'OK';

    public const MISSING = 'MISSING';

    public const MISMATCH = 'MISMATCH';
}

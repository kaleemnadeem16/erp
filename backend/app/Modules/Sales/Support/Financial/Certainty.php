<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial;

/** How much of an amount is known (ADR 0110/0120). Unknown is a state with a reason, never a zero. */
enum Certainty: string
{
    case Complete = 'COMPLETE';
    case Partial = 'PARTIAL';
    case Unknown = 'UNKNOWN';
}

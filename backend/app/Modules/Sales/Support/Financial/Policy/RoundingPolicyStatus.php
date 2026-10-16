<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Policy;

enum RoundingPolicyStatus: string
{
    /** Created, not yet in force; the only state without an effective date. */
    case Draft = 'DRAFT';

    /** The open-ended head of the timeline: in force from its effective date until a successor is activated. */
    case Active = 'ACTIVE';

    /** Closed by its successor at an exclusive end date. History: still resolves for the dates it covered. */
    case Superseded = 'SUPERSEDED';
}

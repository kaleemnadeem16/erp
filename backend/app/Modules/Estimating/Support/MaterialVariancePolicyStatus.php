<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

/** Same lifecycle as OverheadPolicyStatus: DRAFT is the only editable state; SUPERSEDED is history. */
enum MaterialVariancePolicyStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
}

<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** Same lifecycle as the overhead and material-variance policies. */
enum ApprovalPolicyStatus: string
{
    case Draft = 'DRAFT';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
}

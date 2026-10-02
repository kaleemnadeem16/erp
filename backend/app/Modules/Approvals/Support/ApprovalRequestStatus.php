<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** PENDING is the only open state; everything else is history. */
enum ApprovalRequestStatus: string
{
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Withdrawn = 'WITHDRAWN';
    case Invalidated = 'INVALIDATED';
    case Expired = 'EXPIRED';
}

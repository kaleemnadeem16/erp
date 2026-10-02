<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

/** CLOSED: the request ended (rejected, withdrawn, invalidated or expired) before this step decided. */
enum ApprovalStepStatus: string
{
    case Waiting = 'WAITING';
    case Open = 'OPEN';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Closed = 'CLOSED';
}

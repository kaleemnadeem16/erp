<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

enum DutyStatus: string
{
    /** Registered, enforced by a delivered Action and guard. */
    case Active = 'ACTIVE';

    /** Approved and specified, with no supported consumer yet: not registered, not grantable, refused by SalesAccess. */
    case Specified = 'SPECIFIED';

    /** Reserved by design only (D-S): needs its own approved and delivered contract before it may even become Specified. */
    case Dormant = 'DORMANT';
}

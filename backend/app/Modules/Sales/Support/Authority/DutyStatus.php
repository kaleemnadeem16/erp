<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

enum DutyStatus: string
{
    /** Registered, enforced by a delivered Action and guard. */
    case Active = 'ACTIVE';

    /** Approved and specified, with no supported consumer yet: not registered, not grantable, refused by SalesAccess. */
    case Specified = 'SPECIFIED';

    /**
     * Its guarded Action exists and is tested, but the duty is not registered and `SalesAccess` refuses every caller, so the operation is disabled. It
     * becomes ACTIVE only with an owner-reviewed holder recipe; until then nobody can hold it, not even through a hand-made permission row.
     */
    case Staged = 'STAGED';

    /** Reserved by design only (D-S): needs its own approved and delivered contract before it may even become Specified. */
    case Dormant = 'DORMANT';
}

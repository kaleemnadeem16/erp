<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Models\User;

/**
 * Optional companion of {@see ReleasedServiceReceiver}: the receiver's own independent authority check, asked at the START of every release request — a fresh release AND a
 * key or business replay — so that revoking the receiving duty (or suspending the member) stops a replay from handing back the Job reference. It must throw an
 * AuthorizationException and write nothing; a receiver without it is judged only when it receives.
 */
interface ReleaseReceiverAuthority
{
    public function assertMayReceive(User $actor): void;
}

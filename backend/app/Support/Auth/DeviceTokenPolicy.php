<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;

/**
 * The one place that decides how long a device token lives and what it may do. Today the answer is the same for everyone and comes from
 * configuration (config/signin.php). Because sign-in asks this class and never reads configuration itself, a company-configurable rule
 * — a shorter lifetime for some roles, a service credential with narrow abilities, a lifetime that depends on how strongly the person
 * authenticated — is a change here, not in the sign-in code (docs/decisions/0088-*.md).
 */
final class DeviceTokenPolicy
{
    public function rulesFor(User $user): DeviceTokenRules
    {
        $ceiling = (int) config('sanctum.expiration');
        $lifetime = (int) config('signin.device_token_lifetime_minutes');

        // Never longer than the hard ceiling, and always finite.
        return new DeviceTokenRules($ceiling > 0 ? min($lifetime, $ceiling) : $lifetime);
    }
}

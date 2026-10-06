<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The checks that stand between a correct password and a signed-in person. Empty today, so sign-in behaves exactly as it always has.
 * A later capability (two-factor, device confirmation, company-mandated re-authentication) registers its requirement from its own
 * service provider, the way modules register permissions and routes, and the first one that is unmet stops the sign-in.
 */
final class SignInRequirements
{
    /** @var list<SignInRequirement> */
    private array $requirements = [];

    public function register(SignInRequirement $requirement): void
    {
        $this->requirements[] = $requirement;
    }

    public function firstUnmet(User $user, Request $request): ?SignInChallenge
    {
        foreach ($this->requirements as $requirement) {
            $challenge = $requirement->challenge($user, $request);
            if ($challenge !== null) {
                return $challenge;
            }
        }

        return null;
    }
}

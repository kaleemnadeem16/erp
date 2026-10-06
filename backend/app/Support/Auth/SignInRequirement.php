<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Something more than a correct password that a company or the product may require before a session or device token is issued: a
 * second factor, a confirmed device, a recent re-authentication. An implementation looks at the person and the request and returns
 * the challenge that is still unmet, or null when it is satisfied (or does not apply to this person, for instance because the company
 * has not switched it on). It is registered with SignInRequirements; sign-in itself never changes (docs/decisions/0088-*.md, Q37).
 */
interface SignInRequirement
{
    public function challenge(User $user, Request $request): ?SignInChallenge;
}

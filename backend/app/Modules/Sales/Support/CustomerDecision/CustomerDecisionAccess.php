<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\CustomerDecision;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\SalesAccess;
use App\Support\Scope\DecisionActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may touch a customer decision case, judged twice by every Action (authorization.md R1): `early` is the fast refusal on the held actor, `decide` is the decision itself
 * and runs INSIDE the operation's transaction, before any lock of the case or any receipt look-up: it takes the shared company access fence, rereads the person as an ACTIVE member
 * with fresh duties (`DecisionActor`), and asks the Sales manifest and the duty's record scope (`SalesAccess`: a STAGED duty is refused to everyone, a limited scope fails closed).
 * Both refuse with the same 403, so a suspended or stripped person is refused even when replaying a stored result.
 */
final class CustomerDecisionAccess
{
    /** The fast outer check on the actor as loaded. Not the decision. */
    public static function early(User $actor, string $duty, string $doing): void
    {
        ActiveActor::require($actor, $doing);
        SalesAccess::require($actor, $duty, $doing);
    }

    /** The decision, inside the caller's transaction. Returns the person as stored now. */
    public static function decide(User $actor, string $duty, string $doing): User
    {
        try {
            $fresh = DecisionActor::require($actor, $duty, $doing);
        } catch (DomainInvariantException) {
            throw new AuthorizationException("You are not permitted to {$doing}.");
        }
        SalesAccess::require($fresh, $duty, $doing);

        return $fresh;
    }
}

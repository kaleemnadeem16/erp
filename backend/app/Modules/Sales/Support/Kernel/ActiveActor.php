<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Kernel;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Is this person, right now, an ACTIVE member of the active company? Answered from the database, never from the hydrated `$actor`: a person suspended or removed after
 * being loaded (a long-lived worker, a held object) is refused. A duty, a scope and a manifest say what a role may do; they say nothing about whether the person is
 * still a member, so every Action that reaches protected evidence asks this first. Bounded: one indexed read, no write.
 */
final class ActiveActor
{
    /** The person as stored now, or null when they are not an active member of this company. */
    public static function current(User $actor): ?User
    {
        return User::query()->whereKey($actor->getKey())->where('company_id', app(CompanyContext::class)->id())->where('status', MemberStatus::Active->value)->first();
    }

    public static function require(User $actor, string $doing = 'do that'): User
    {
        return self::current($actor) ?? throw new AuthorizationException("You are not permitted to {$doing}.");
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one question every Sales surface asks: may this person use this capability, here? (ADR 0106)
 *
 * Sales records are company-wide for now: a person who holds a Sales capability reaches every lead and opportunity of the company. Own, assigned and
 * unit visibility is **not** implemented, and a restriction must never be a filter that an API call can walk around — so a person whom an administrator
 * has limited to "my units" for any Sales capability is refused outright (403) until restricted visibility is properly built, rather than being shown
 * everything or a client-side subset. Capability and scope are separate questions (ADR 0028); both are checked.
 */
final class SalesAccess
{
    public static function may(?User $user, string $capability): bool
    {
        if ($user === null || ! $user->can($capability)) {
            return false;
        }

        return app(RecordScope::class)->resolve($user, PermissionVocabulary::all())->isAll();
    }

    public static function require(?User $user, string $capability, string $doing = 'do that'): void
    {
        if (! self::may($user, $capability)) {
            throw new AuthorizationException("You are not permitted to {$doing}.");
        }
    }

    /** Reading is allowed to those who hold the read capability or any capability that works on the same records. */
    public static function mayRead(?User $user): bool
    {
        foreach ([PermissionVocabulary::PIPELINE_VIEW, PermissionVocabulary::LEAD_MANAGE, PermissionVocabulary::OPPORTUNITY_MANAGE, PermissionVocabulary::OPPORTUNITY_ASSIGN, PermissionVocabulary::OPPORTUNITY_CLOSE] as $capability) {
            if (self::may($user, $capability)) {
                return true;
            }
        }

        return false;
    }

    public static function requireRead(?User $user): void
    {
        if (! self::mayRead($user)) {
            throw new AuthorizationException('You are not permitted to view the sales pipeline.');
        }
    }
}

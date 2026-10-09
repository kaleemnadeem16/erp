<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Who may ask "does this identity already exist?" and who may see archived candidates (ADR 0105). Searching identities is wider than reading one
 * known Party, so it needs a duty that already involves choosing or creating a Party; archived identities need the capability to restore them.
 * Other modules that choose a Party (Sales) add their own capabilities here rather than widening Party maintenance.
 */
final class PartyCandidateAccess
{
    /** @var list<string> */
    private static array $searchers = [PermissionVocabulary::PARTY_MANAGE];

    /** Register a capability whose holders choose or create Parties (called by the owning module's provider). */
    public static function allow(string $permission): void
    {
        if (! in_array($permission, self::$searchers, true)) {
            self::$searchers[] = $permission;
        }
    }

    public static function mayRestore(User $actor): bool
    {
        return $actor->can(PermissionVocabulary::PARTY_MANAGE);
    }

    public static function mayAsk(User $actor): bool
    {
        foreach (self::$searchers as $permission) {
            try {
                if ($actor->can($permission)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                // A capability another module registers but this company has not created yet confers nothing.
            }
        }

        return false;
    }

    public static function require(User $actor): void
    {
        if (! self::mayAsk($actor)) {
            throw new AuthorizationException;
        }
    }
}

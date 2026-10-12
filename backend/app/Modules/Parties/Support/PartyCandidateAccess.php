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
    /** @var array<string, (\Closure(User): bool)|null> capability → the owning module's own eligibility (capability and its scope), or null for a plain capability */
    private static array $searchers = [PermissionVocabulary::PARTY_MANAGE => null];

    /**
     * Register a capability whose holders choose or create Parties (called by the owning module's provider). A module whose duty is
     * record-scoped passes its own eligibility predicate, so that a duty limited to "my units" is not enough here either: the
     * permission string alone is never the answer.
     *
     * @param  (\Closure(User): bool)|null  $eligible
     */
    public static function allow(string $permission, ?\Closure $eligible = null): void
    {
        if (! array_key_exists($permission, self::$searchers) || $eligible !== null) {
            self::$searchers[$permission] = $eligible;
        }
    }

    public static function mayRestore(User $actor): bool
    {
        return $actor->can(PermissionVocabulary::PARTY_MANAGE);
    }

    public static function mayAsk(User $actor): bool
    {
        foreach (self::$searchers as $permission => $eligible) {
            try {
                if ($eligible !== null ? $eligible($actor) : $actor->can($permission)) {
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

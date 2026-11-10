<?php

declare(strict_types=1);

namespace App\Support\Scope;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\CompanyContext;

/**
 * The person who decides an irreversible operation, as stored NOW. An Action checks the held actor early so it can refuse fast;
 * the decision itself is taken here, inside its transaction: the company access barrier is taken first (shared, so security
 * writers wait for the operation or are seen by it), then the actor is read afresh as an ACTIVE member of this company with no
 * cached duties. A person suspended, removed or stripped of the duty after being loaded is refused.
 */
final class DecisionActor
{
    /**
     * @param  string|list<string>  $permissions  any one of them is enough
     */
    public static function require(User $held, string|array $permissions, string $action, ?string $field = null): User
    {
        $fresh = self::fresh($held, $action, $field);
        if (! $fresh->hasAnyPermission((array) $permissions)) {
            throw new DomainInvariantException("The actor is not permitted to {$action}.", $field);
        }

        return $fresh;
    }

    /**
     * @param  list<string>  $permissions  every one of them is needed
     */
    public static function requireAll(User $held, array $permissions, string $action, ?string $field = null): User
    {
        $fresh = self::fresh($held, $action, $field);
        foreach ($permissions as $permission) {
            if (! $fresh->hasPermissionTo($permission)) {
                throw new DomainInvariantException("The actor is not permitted to {$action}.", $field);
            }
        }

        return $fresh;
    }

    /** Barrier first, then an active-only reload; the caller checks whatever duties its own rules need on the returned user. */
    public static function fresh(User $held, string $action, ?string $field = null): User
    {
        // Barrier first, before any row lock of the operation; the fence refuses outside a transaction.
        app(CompanyAccessFence::class)->lock();
        $fresh = User::query()->whereKey($held->getKey())->where('company_id', app(CompanyContext::class)->id())
            ->where('status', MemberStatus::Active->value)->first();
        if ($fresh === null) {
            throw new DomainInvariantException("The actor is not permitted to {$action}.", $field);
        }

        return $fresh;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Jobs\Support;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Jobs\Support\Authority\JobDutyManifest;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one question every future Jobs surface asks: may this person use this capability, here? It is the module-local capability and scope predicate only; the
 * owning operation still checks its own record eligibility, source readiness and replay rules, and nothing here grants, registers or enforces any Action.
 *
 *  - The duty must be ACTIVE in the Jobs manifest. Unknown, SPECIFIED and STAGED names are refused even when a permission row, an ALL scope or a mandate exists.
 *  - The actor is read again, in the active company, and must be a currently active member: a suspended, foreign or stale-hydrated user denies.
 *  - The person must hold THAT duty (`can`) and RecordScope must resolve THAT duty to ALL. A limited path alone, or an unrelated unrestricted duty, never
 *    qualifies; limited modes are unsupported and refuse rather than filter.
 *  - Several duties are judged independently (`mayAll`); an empty list denies. There is deliberately no "any writer may read" helper.
 */
final class JobAccess
{
    public static function may(?User $user, string $capability): bool
    {
        if ($user === null || ! app(JobDutyManifest::class)->isActive($capability)) {
            return false;
        }
        $current = User::query()->where('company_id', app(CompanyContext::class)->id())->whereKey($user->id)->first();
        if ($current === null || $current->status !== MemberStatus::Active || ! $current->can($capability)) {
            return false;
        }

        return app(RecordScope::class)->resolve($current, $capability)->isAll();
    }

    /** Every duty, each by its own scope; none stands in for another. Combined operations (assign AND start) need both. */
    public static function mayAll(?User $user, string ...$capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if (! self::may($user, $capability)) {
                return false;
            }
        }

        return $capabilities !== [];
    }

    public static function require(?User $user, string $capability, string $doing = 'do that'): void
    {
        if (! self::may($user, $capability)) {
            throw new AuthorizationException("You are not permitted to {$doing}.");
        }
    }
}

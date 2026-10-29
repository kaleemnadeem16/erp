<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;

final class TaxAccess
{
    public static function requireConfiguration(User $actor): void
    {
        self::requireDuty($actor, TaxDutyManifest::CONFIGURE);
    }

    public static function requireProfile(User $actor): void
    {
        self::requireDuty($actor, TaxDutyManifest::PROFILE);
    }

    /** Re-read security identity rather than trusting a stale hydrated actor (including on replay). */
    public static function currentActor(User $actor): User
    {
        $current = User::query()->find($actor->id);
        if ($current === null || (int) $actor->company_id !== app(CompanyContext::class)->id()
            || (int) $current->company_id !== app(CompanyContext::class)->id() || $current->status !== MemberStatus::Active) {
            throw new AuthorizationException('Tax operations are not available to this actor.');
        }

        return $current;
    }

    private static function requireDuty(User $actor, string $duty): void
    {
        $actor = self::currentActor($actor);
        if ((int) $actor->company_id !== app(CompanyContext::class)->id()
            || ! app(TaxDutyManifest::class)->isActive($duty)
            || ! $actor->can($duty)
            || ! app(RecordScope::class)->resolve($actor, $duty)->isAll()) {
            throw new AuthorizationException('Tax operations are not available to this actor.');
        }
    }
}

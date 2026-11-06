<?php

declare(strict_types=1);

namespace App\Modules\Company\Support;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Support\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

/** The one place the Company duties behind a manifest are checked, for the request and for the Action alike (authorization.md R1). */
final class CompanyAccess
{
    /** True when this person may change the company's branding right now: a current active member, the duty ACTIVE in the manifest and held. */
    public static function mayManageBranding(User $actor): bool
    {
        $current = User::query()->find($actor->id);

        return $current !== null && (int) $current->company_id === app(CompanyContext::class)->id() && $current->status === MemberStatus::Active
            && app(CompanyDutyManifest::class)->isActive(CompanyDutyManifest::BRANDING) && $current->can(CompanyDutyManifest::BRANDING);
    }

    public static function requireBranding(User $actor): void
    {
        if (! self::mayManageBranding($actor)) {
            throw new AuthorizationException('Changing the company branding is not available to this actor.');
        }
    }
}

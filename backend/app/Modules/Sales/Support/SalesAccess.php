<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Sales\Support\Authority\SalesDutyManifest;
use App\Support\Scope\RecordScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The one question every Sales surface asks: may this person use this capability, here? (ADR 0106)
 *
 * Sales records are company-wide for now: a person who holds a Sales capability reaches every lead and opportunity of the company. Own, assigned and
 * unit visibility is **not** implemented, and a restriction must never be a filter that an API call can walk around — so a person whom an administrator
 * has limited to "my units" for the capability being used is refused outright (403) until restricted visibility is properly built, rather than being shown
 * everything or a client-side subset. Capability and scope are separate questions (ADR 0028); both are checked.
 */
final class SalesAccess
{
    public static function may(?User $user, string $capability): bool
    {
        // A duty the manifest does not list as ACTIVE is not a Sales capability yet: a hand-made permission row for a specified or dormant duty confers nothing.
        if ($user === null || ! app(SalesDutyManifest::class)->isActive($capability) || ! $user->can($capability)) {
            return false;
        }

        // The scope of THIS duty only: an unrestricted grant of an unrelated Sales duty (stages, numbering) must never widen a restricted one.
        return app(RecordScope::class)->resolve($user, $capability)->isAll();
    }

    /**
     * Several duties at once, each judged by its own scope and none standing in for another — an unrestricted read cannot rescue a restricted sensitive
     * duty, nor the reverse. Use it where a sensitive operation also needs an independent right (ADR 0114).
     */
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

    /** Choosing who is responsible means seeing who can be: the people who create, assign or manage deals and enquiries. */
    public static function mayChoosePeople(?User $user): bool
    {
        foreach ([PermissionVocabulary::LEAD_MANAGE, PermissionVocabulary::OPPORTUNITY_MANAGE, PermissionVocabulary::OPPORTUNITY_ASSIGN] as $capability) {
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

    /**
     * Correcting recorded responsibility needs the dedicated duty AND an independent, legitimate right to read the pipeline, each judged by its own
     * scope: an unrestricted read cannot rescue a restricted correction, nor the reverse (ADR 0114).
     */
    public static function mayCorrectAttribution(?User $user): bool
    {
        return self::may($user, PermissionVocabulary::OPPORTUNITY_ATTRIBUTION_CORRECT) && self::mayRead($user);
    }

    public static function requireCorrectAttribution(?User $user): void
    {
        if (! self::mayCorrectAttribution($user)) {
            throw new AuthorizationException('You are not permitted to correct recorded responsibility.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Parties\Support;

use App\Models\User;
use App\Support\ActionAuthorization;

/** ADR 0084: terms stay readable; credit and both field-change powers are separate capabilities. */
final class AccountTermsAccess
{
    public static function canViewCredit(?User $actor): bool
    {
        return $actor?->canAny(['parties.credit-limit.view', 'parties.credit-limit.manage']) === true;
    }

    /** @param array<string, mixed> $attributes */
    public static function canWrite(?User $actor, array $attributes): bool
    {
        return (! array_key_exists('credit_limit', $attributes) || $actor?->can('parties.credit-limit.manage') === true)
            && (! array_key_exists('payment_terms_days', $attributes) || $actor?->can('parties.payment-terms.manage') === true);
    }

    /** @param array<string, mixed> $attributes */
    public static function requireWrite(User $actor, array $attributes): void
    {
        if (array_key_exists('credit_limit', $attributes)) {
            ActionAuthorization::require($actor, 'parties.credit-limit.manage', 'change customer credit limits');
        }
        if (array_key_exists('payment_terms_days', $attributes)) {
            ActionAuthorization::require($actor, 'parties.payment-terms.manage', 'change payment terms');
        }
    }
}

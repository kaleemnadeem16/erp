<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Authority;

use App\Modules\Sales\Support\PermissionVocabulary;
use Illuminate\Support\Facades\Log;

/**
 * The one way the staged quotation duties become usable on a developer's machine, and only there. The shipped catalogue is untouched (the duties stay STAGED in
 * `SalesDutyCatalogue`, and `PermissionVocabulary::all()` still reads the shipped manifest); this only chooses which manifest the container hands out. It needs BOTH
 * `SALES_DEV_QUOTATION_DUTIES=true` and `APP_ENV=local` — any other environment (production, staging, testing) ignores the flag, logs a critical warning and keeps
 * the shipped STAGED defaults, so a stray variable can never switch authority on. Turning the duties ACTIVE grants nobody anything: a person still needs an explicit
 * grant (`sales:dev-grant-quotation`), and the duties stay outside every automatic grant.
 */
final class SalesDevPreview
{
    /** @var list<string> */
    public const DUTIES = [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE];

    /**
     * The quotation document feature, so the dev admin can generate, download and record delivery of an issued quotation's PDF on a developer's machine. Never the
     * sensitive operator duty `sales.document.repair`, and never anything that assigns or sends.
     *
     * @var list<string>
     */
    public const DOCUMENT_DUTIES = [
        PermissionVocabulary::DOCUMENT_GENERATE, PermissionVocabulary::DOCUMENT_VIEW, PermissionVocabulary::DOCUMENT_DOWNLOAD,
        PermissionVocabulary::DELIVERY_VIEW, PermissionVocabulary::DELIVERY_RECORD,
    ];

    /**
     * The issue duty, owner-approved for the local preview only (#76 review): the checker half of maker/checker, so a developer's explicitly granted login can use the Issue
     * button on localhost. It needs an independent `sales.quotation.view` (already in DUTIES). Production stays STAGED: this list is read only when the local switch is on.
     *
     * @var list<string>
     */
    public const ISSUE_DUTIES = [PermissionVocabulary::QUOTATION_ISSUE];

    /**
     * Activated for the local preview (owner decisions, 2026-11): the access-management family (reassign, share, revoke - ADR 0156), the pricing concessions and the
     * approver duty. They are switched ON in the manifest only; `all()` (what `sales:dev-grant-quotation` hands out) does NOT include them, so a login holds one only
     * through an explicit, reviewed path: the demo presets (`sales:demo-preset`, ADR 0159 + 0161), the bootstrapped synthetic access operator, or a seeder persona.
     * Delegation of approval stays off (the approval subject refuses delegates); the shipped manifest keeps every one of these STAGED.
     *
     * @var list<string>
     */
    public const ACCESS_DUTIES = [PermissionVocabulary::QUOTATION_ASSIGN, PermissionVocabulary::QUOTATION_ACCESS_DELEGATE, PermissionVocabulary::QUOTATION_ACCESS_REVOKE];

    /** @var list<string> */
    public const CONCESSION_DUTIES = [PermissionVocabulary::QUOTATION_DISCOUNT, PermissionVocabulary::QUOTATION_PRICE_OVERRIDE, PermissionVocabulary::QUOTATION_COMMERCIAL_VIEW];

    /** @var list<string> */
    public const APPROVE_DUTIES = [PermissionVocabulary::QUOTATION_APPROVE];

    /**
     * The discount authority configuration screens (Settings > Discount authority and its price references), local preview only. Propose (configure) and approve are
     * distinct duties; the server refuses a person approving a reference they proposed, so holding both on the demo login never lets one person do both steps.
     * Nothing here is in `all()`; only the 'full' demo preset grants them.
     *
     * @var list<string>
     */
    public const AUTHORITY_CONFIG_DUTIES = [PermissionVocabulary::FINANCIAL_CONFIGURE, PermissionVocabulary::PRICE_REFERENCE_APPROVE];

    /** @return list<string> every duty the local preview turns on and `sales:dev-grant-quotation` hands out */
    public static function all(): array
    {
        return [...self::DUTIES, ...self::DOCUMENT_DUTIES, ...self::ISSUE_DUTIES];
    }

    /** @return list<string> every duty the local preview makes ACTIVE in the manifest (a superset of `all()`; activation grants nobody anything) */
    public static function activated(): array
    {
        return [...self::all(), ...self::ACCESS_DUTIES, ...self::CONCESSION_DUTIES, ...self::APPROVE_DUTIES, ...self::AUTHORITY_CONFIG_DUTIES];
    }

    public static function requested(): bool
    {
        return (bool) config('sales.dev_preview.quotation_duties');
    }

    /** True only when the flag is set and this is a local environment. */
    public static function enabled(): bool
    {
        return self::requested() && app()->environment('local');
    }

    public static function manifest(): SalesDutyManifest
    {
        $shipped = SalesDutyManifest::shipped();
        if (! self::requested()) {
            return $shipped;
        }
        if (! app()->environment('local')) {
            Log::critical('SALES_DEV_QUOTATION_DUTIES is set outside a local environment and was ignored; the quotation duties stay STAGED.', ['environment' => app()->environment()]);

            return $shipped;
        }

        return $shipped->withActivated(self::activated());
    }
}

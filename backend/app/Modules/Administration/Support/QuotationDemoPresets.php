<?php

declare(strict_types=1);

namespace App\Modules\Administration\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Sales\Support\PermissionVocabulary as Duty;

/**
 * The named, switchable quotation presets for the main demo login (ADR 0156, 0159, 0161). Demo packaging only: it chooses WHICH existing, reviewed assignments a synthetic login gets; scope
 * evaluation, record authority and every server-side Action are untouched. Shared by the apply Action, the `sales:demo-preset` command and the demo seeders, so there is one definition.
 *
 * Two layers, both quotation-only:
 *  - ACCESS roles (view, prepare, assign, delegate, revoke) with a scope per assignment. Applied through the guarded `SyncMemberAccess::patchQuotationDemo` by the synthetic operator, which
 *    preserves every unrelated right of the login and audits before/after.
 *  - WORKFLOW add-ons (issue, discount, price override, commercial view, approve, and for 'full' only the discount authority configure/reference-approve duties): direct grants applied only under the local preview switch, exactly like the existing
 *    `sales:dev-grant-quotation`. They are capabilities layered on the access role's records; they are never granted by the patch and never include repair.
 */
final class QuotationDemoPresets
{
    public const ROLE_PREFIX = 'Quotation demo: ';

    /** The workflow duties a preset may add, and the full set the apply Action keeps in step (a preset without one takes it away). */
    public const ADDONS = [Duty::QUOTATION_ISSUE, Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::QUOTATION_COMMERCIAL_VIEW, Duty::QUOTATION_APPROVE, Duty::FINANCIAL_CONFIGURE, Duty::PRICE_REFERENCE_APPROVE];

    /**
     * role => the quotation-only duties the role carries.
     *
     * @return array<string, list<string>>
     */
    public static function roles(): array
    {
        return [
            self::ROLE_PREFIX.'viewer' => [Duty::QUOTATION_VIEW],
            self::ROLE_PREFIX.'preparer' => [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE],
            self::ROLE_PREFIX.'delegate' => [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE, Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE],
            self::ROLE_PREFIX.'access manager' => [Duty::QUOTATION_VIEW, Duty::QUOTATION_ASSIGN, Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE],
            self::ROLE_PREFIX.'governor' => [Duty::QUOTATION_VIEW, Duty::QUOTATION_PREPARE, Duty::QUOTATION_ASSIGN, Duty::QUOTATION_ACCESS_DELEGATE, Duty::QUOTATION_ACCESS_REVOKE],
        ];
    }

    /**
     * preset => description, the role it assigns (null = none), that assignment's scope, and the workflow add-ons.
     *
     * @return array<string, array{about: string, role: string|null, scope: string|null, addons: list<string>}>
     */
    public static function all(): array
    {
        $viewer = self::ROLE_PREFIX.'viewer';
        $preparer = self::ROLE_PREFIX.'preparer';

        return [
            'off' => ['about' => 'No quotation rights at all (default-deny). Unrelated ERP access is kept.', 'role' => null, 'scope' => null, 'addons' => []],
            'viewer' => ['about' => 'View every quotation, change nothing.', 'role' => $viewer, 'scope' => 'ALL', 'addons' => []],
            'preparer' => ['about' => 'View and prepare only quotations you are responsible for (OWN).', 'role' => $preparer, 'scope' => AccessScopeLimit::OWN, 'addons' => []],
            'collaborator' => ['about' => 'View and prepare your own quotations plus those named to you in a share (OWN or named share).', 'role' => $preparer, 'scope' => AccessScopeLimit::OWN_OR_DELEGATED, 'addons' => []],
            'shared-viewer' => ['about' => 'View only what is named to you in a share (no own or unit reach, no prepare).', 'role' => $viewer, 'scope' => AccessScopeLimit::OWN_OR_DELEGATED, 'addons' => []],
            'delegate' => ['about' => 'Prepares and views own quotations (OWN) and may share them by name (VIEW/PREPARE) and revoke those shares.', 'role' => self::ROLE_PREFIX.'delegate', 'scope' => AccessScopeLimit::OWN, 'addons' => []],
            'concessions' => ['about' => 'Preparer (OWN) who may enter discounts and price overrides and see commercial figures; the approval engine decides if they need sign-off.', 'role' => $preparer, 'scope' => AccessScopeLimit::OWN, 'addons' => [Duty::QUOTATION_DISCOUNT, Duty::QUOTATION_PRICE_OVERRIDE, Duty::QUOTATION_COMMERCIAL_VIEW]],
            'manager' => ['about' => 'View and prepare quotations of the units you belong to (MY_UNITS).', 'role' => $preparer, 'scope' => AccessScopeLimit::MY_UNITS, 'addons' => []],
            'issuer' => ['about' => 'Issuer-checker: sees every quotation and may issue a ready one; cannot prepare or approve.', 'role' => $viewer, 'scope' => 'ALL', 'addons' => [Duty::QUOTATION_ISSUE]],
            'approver' => ['about' => 'Sees every quotation and may approve or reject a recorded discount/override need; cannot prepare, and never approves what they prepared.', 'role' => $viewer, 'scope' => 'ALL', 'addons' => [Duty::QUOTATION_APPROVE]],
            'governor' => ['about' => 'Manager (MY_UNITS) who can also reassign owners, change the access unit, share and revoke named access within those units.', 'role' => self::ROLE_PREFIX.'governor', 'scope' => AccessScopeLimit::MY_UNITS, 'addons' => []],
            'access-manager' => ['about' => 'Company-wide: view, reassign, share and revoke (recovery management). Cannot prepare or issue.', 'role' => self::ROLE_PREFIX.'access manager', 'scope' => 'ALL', 'addons' => []],
            'full' => ['about' => 'Everything the demo offers, company-wide: view, prepare, assign, share, revoke, issue, discount, price override, commercial figures, approve, and the Discount authority settings (configure, approve price references). Never repair.', 'role' => self::ROLE_PREFIX.'governor', 'scope' => 'ALL', 'addons' => self::ADDONS],
        ];
    }

    /** @return array{about: string, role: string|null, scope: string|null, addons: list<string>} */
    public static function get(string $name): array
    {
        return self::all()[$name] ?? throw new DomainInvariantException('Unknown preset "'.$name.'". Known presets: '.implode(', ', array_keys(self::all())).'.', 'preset');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Company\Support;

use App\Support\PermissionRegistry;

/**
 * The Company duties that are not live yet (ADR 0162). `company.branding.manage` is STAGED: the guarded Action and route exist, but nobody can use them until a reviewed holder
 * recipe activates it here. While STAGED it is not registered in the permission vocabulary at all, so no role editor, member grant or automatic grant can hand it out, and
 * `CompanyAccess` refuses every caller. Activating it is an explicit change to this manifest plus a reviewed grant, never a side effect of seeding or of being an administrator.
 */
final class CompanyDutyManifest
{
    public const BRANDING = 'company.branding.manage';

    /** @param array<string, string> $duties */
    public function __construct(private readonly array $duties = [self::BRANDING => 'STAGED']) {}

    /** @return array<string, string> */
    public function statuses(): array
    {
        return $this->duties;
    }

    public function isActive(string $duty): bool
    {
        return ($this->duties[$duty] ?? null) === 'ACTIVE';
    }

    public function registerWith(PermissionRegistry $registry): void
    {
        $names = array_keys(array_filter($this->duties, static fn (string $status): bool => $status === 'ACTIVE'));
        if ($names === []) {
            return;
        }
        $registry->registerVocabulary('company-duties', $names);
        $registry->excludeFromAutomaticGrants($names, 'Company branding changes what every customer document shows; it needs a reviewed explicit holder.');
    }
}

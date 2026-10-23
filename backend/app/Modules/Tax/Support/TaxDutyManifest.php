<?php

declare(strict_types=1);

namespace App\Modules\Tax\Support;

use App\Support\PermissionRegistry;

/** FR2a delivers configuration Actions, disabled until reviewed holder rollout. FR2b owns profiles. */
final class TaxDutyManifest
{
    public const CONFIGURE = 'tax.configuration.manage';

    /** @return array<string, string> */
    public function statuses(): array
    {
        return $this->duties;
    }

    /** @param array<string, string> $duties */
    public function __construct(private readonly array $duties = [self::CONFIGURE => 'STAGED', 'tax.profile.manage' => 'SPECIFIED']) {}

    public function isActive(string $duty): bool
    {
        return ($this->statuses()[$duty] ?? null) === 'ACTIVE';
    }

    public function registerWith(PermissionRegistry $registry): void
    {
        $names = array_keys(array_filter($this->statuses(), static fn (string $status): bool => $status === 'ACTIVE'));
        if ($names === []) {
            return;
        }
        $registry->registerVocabulary('tax', $names);
        $registry->excludeFromAutomaticGrants($names, 'Tax duties require reviewed explicit holders or a verified full-access mandate (ADR 0138).');
    }
}

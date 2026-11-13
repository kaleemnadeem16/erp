<?php

declare(strict_types=1);

namespace App\Support\JobSource;

use InvalidArgumentException;

/**
 * Registry of source-family applicability authorities. Source modules register themselves; the Sales release proof reads it. A REQUIRED family that is not
 * registered is UNKNOWN (fail closed): registration is the owner's explicit statement that it has inspected its own writer set.
 */
final class JobSourceFamilies
{
    /** The families the first pilot must hear from before any NOT_APPLICABLE can stand. */
    public const REQUIRED = ['INVENTORY_MATERIAL', 'PURCHASING_SUPPLY'];

    /** @var array<string, SourceFamilyApplicability> */
    private array $authorities = [];

    public function register(SourceFamilyApplicability $authority): void
    {
        $family = $authority->family();
        if (isset($this->authorities[$family])) {
            throw new InvalidArgumentException("Source family [{$family}] is registered twice.");
        }
        $this->authorities[$family] = $authority;
    }

    /** @return list<string> */
    public function registered(): array
    {
        $names = array_keys($this->authorities);
        sort($names);

        return $names;
    }

    /**
     * Every required family's answer, plus any other registered family. A missing required family is an UNKNOWN assessment, never an omission.
     *
     * @return list<FamilyAssessment>
     */
    public function assessAll(int $companyId): array
    {
        $out = [];
        foreach (self::REQUIRED as $family) {
            $out[$family] = isset($this->authorities[$family])
                ? $this->authorities[$family]->assess($companyId)
                : FamilyAssessment::unknown($family, 'UNREGISTERED', 'No source owner has registered an applicability authority for this family.');
        }
        foreach ($this->authorities as $family => $authority) {
            $out[$family] ??= $authority->assess($companyId);
        }
        ksort($out);

        return array_values($out);
    }
}

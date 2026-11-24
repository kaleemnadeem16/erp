<?php

declare(strict_types=1);

namespace Tests\E2E\RealBackend;

use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use App\Support\JobSource\SourceFamilyApplicability;

/**
 * TEST ONLY: stand-ins for the Inventory and Purchasing family authorities, whose production registration belongs to their owners (#120/#122). A real application without
 * them reports applicability UNKNOWN and every release refuses. The harness registers these in its own process so the Sales release journey can be exercised end to end; the
 * production registry is untouched.
 */
final class NoBindingFamilies
{
    public static function registry(): JobSourceFamilies
    {
        $families = new JobSourceFamilies;
        foreach (['INVENTORY_MATERIAL', 'PURCHASING_SUPPLY'] as $family) {
            $families->register(new class($family) implements SourceFamilyApplicability
            {
                public function __construct(private string $family) {}

                public function family(): string
                {
                    return $this->family;
                }

                public function assess(int $companyId): FamilyAssessment
                {
                    return FamilyAssessment::notApplicable($this->family, 'TEST_NO_JOB_BINDING.1');
                }
            });
        }

        return $families;
    }
}

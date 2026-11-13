<?php

namespace Tests\Unit\Support;

use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use App\Support\JobSource\SourceFamilyApplicability;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class JobSourceFamiliesTest extends TestCase
{
    private function authority(string $family, string $status): SourceFamilyApplicability
    {
        return new class($family, $status) implements SourceFamilyApplicability
        {
            public function __construct(private string $f, private string $s) {}

            public function family(): string
            {
                return $this->f;
            }

            public function assess(int $companyId): FamilyAssessment
            {
                return $this->s === FamilyAssessment::NOT_APPLICABLE ? FamilyAssessment::notApplicable($this->f, 'V1') : FamilyAssessment::unknown($this->f, 'V1', 'x');
            }
        };
    }

    public function test_a_required_family_that_is_not_registered_is_unknown_never_omitted(): void
    {
        $families = new JobSourceFamilies;
        $families->register($this->authority('INVENTORY_MATERIAL', FamilyAssessment::NOT_APPLICABLE));
        $byFamily = [];
        foreach ($families->assessAll(1) as $a) {
            $byFamily[$a->family] = $a->status;
        }
        $this->assertSame(['INVENTORY_MATERIAL' => 'NOT_APPLICABLE', 'PURCHASING_SUPPLY' => 'UNKNOWN'], $byFamily);
    }

    public function test_an_empty_registry_is_unknown_for_every_required_family(): void
    {
        $statuses = array_map(fn (FamilyAssessment $a): string => $a->status, (new JobSourceFamilies)->assessAll(1));
        $this->assertSame(['UNKNOWN', 'UNKNOWN'], $statuses);
    }

    public function test_a_family_with_supported_bindings_cannot_be_not_applicable_and_duplicates_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FamilyAssessment('INVENTORY_MATERIAL', FamilyAssessment::NOT_APPLICABLE, 'V1', ['x']);
    }

    public function test_registering_a_family_twice_refuses(): void
    {
        $families = new JobSourceFamilies;
        $families->register($this->authority('INVENTORY_MATERIAL', FamilyAssessment::NOT_APPLICABLE));
        $this->expectException(InvalidArgumentException::class);
        $families->register($this->authority('INVENTORY_MATERIAL', FamilyAssessment::NOT_APPLICABLE));
    }
}

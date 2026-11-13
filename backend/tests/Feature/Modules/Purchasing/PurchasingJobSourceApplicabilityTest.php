<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Purchasing;

use App\Modules\Purchasing\Support\JobSource\PurchasingJobSourceApplicability;
use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use Tests\TestCase;

class PurchasingJobSourceApplicabilityTest extends TestCase
{
    public function test_required_supply_authority_is_registered_without_job_bindings(): void
    {
        $answer = collect(app(JobSourceFamilies::class)->assessAll(1))->keyBy('family')->get('PURCHASING_SUPPLY');
        $this->assertNotNull($answer);
        $this->assertSame(FamilyAssessment::NOT_APPLICABLE, $answer->status);
        $this->assertSame(PurchasingJobSourceApplicability::CONTRACT_VERSION, $answer->contractVersion);
        $this->assertSame([], $answer->supportedBindings);
    }

    public function test_foreign_or_invalid_company_has_no_inapplicability_certificate(): void
    {
        foreach ([0, 2] as $company) {
            $this->assertSame(FamilyAssessment::UNKNOWN, (new PurchasingJobSourceApplicability)->assess($company)->status);
        }
    }

    public function test_a_future_supported_supply_binding_is_applicable(): void
    {
        $answer = (new PurchasingJobSourceApplicability(['TEST_VALIDATED_SUPPLY.1']))->assess(1);
        $this->assertSame(FamilyAssessment::APPLICABLE, $answer->status);
        $this->assertSame(['TEST_VALIDATED_SUPPLY.1'], $answer->supportedBindings);
    }
}

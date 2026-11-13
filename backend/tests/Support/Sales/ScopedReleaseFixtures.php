<?php

namespace Tests\Support\Sales;

use App\Models\User;
use App\Modules\Sales\Actions\RecordServiceCompletionBasis;
use App\Modules\Sales\Models\CustomerDecisionEvidence;
use App\Modules\Sales\Support\Handover\Release\ScopeHeader;
use App\Support\JobSource\FamilyAssessment;
use App\Support\JobSource\JobSourceFamilies;
use App\Support\JobSource\SourceFamilyApplicability;

/**
 * Release fixtures for the corrected applicability contract (#122): register fake source-family authorities (production registration belongs to the Inventory and
 * Purchasing owners, so a real app without them is UNKNOWN) and record a basis that rests on its OWN customer-agreement evidence.
 */
trait ScopedReleaseFixtures
{
    public const AGREED = 'The fixed service is complete when the customer-named contact confirms delivery. No materials and no subcontractors are included.';

    /** Replace the singleton registry with fakes. Statuses are NOT_APPLICABLE / APPLICABLE / UNKNOWN; null leaves that family unregistered. */
    protected function registerSourceFamilies(?string $inventory = FamilyAssessment::NOT_APPLICABLE, ?string $purchasing = FamilyAssessment::NOT_APPLICABLE, string $contract = 'TEST_NO_JOB_BINDING.1'): JobSourceFamilies
    {
        $families = new JobSourceFamilies;
        foreach (['INVENTORY_MATERIAL' => $inventory, 'PURCHASING_SUPPLY' => $purchasing] as $family => $status) {
            if ($status === null) {
                continue;
            }
            $families->register(new class($family, $status, $contract) implements SourceFamilyApplicability
            {
                public function __construct(private string $family, private string $status, private string $contract) {}

                public function family(): string
                {
                    return $this->family;
                }

                public function assess(int $companyId): FamilyAssessment
                {
                    return match ($this->status) {
                        FamilyAssessment::NOT_APPLICABLE => FamilyAssessment::notApplicable($this->family, $this->contract),
                        FamilyAssessment::APPLICABLE => FamilyAssessment::applicable($this->family, $this->contract, ['TEST_BINDING']),
                        default => FamilyAssessment::unknown($this->family, $this->contract, 'test'),
                    };
                }
            });
        }
        $this->app->instance(JobSourceFamilies::class, $families);

        return $families;
    }

    /** New written, authority-verified customer-agreement evidence of the case (recorded after the acceptance's own consent evidence). */
    protected function appendAgreementEvidence(int $caseId, int $recorderId, string $text = self::AGREED, string $sourceKind = 'EMAIL', string $authority = 'DOCUMENT_ON_FILE'): int
    {
        return (int) CustomerDecisionEvidence::create(['case_id' => $caseId, 'source_kind' => $sourceKind, 'authority_verification_basis' => $authority, 'customer_representative_snapshot' => 'Synthetic buyer',
            'occurrence_precision' => 'UNKNOWN', 'factual_description' => $text, 'observation_state' => 'OBSERVED', 'recorded_by' => $recorderId])->id;
    }

    /**
     * A basis on its own agreement evidence that excludes materials and subcontractors (the only basis that can make the pilot's applicability NOT_APPLICABLE).
     *
     * @return array{basis_id:int,version:int,case_id:int,replayed:bool}
     */
    protected function recordScopedBasis(User $actor, string $key, int $acceptanceId, int $caseId, string $text = self::AGREED, string $materials = 'EXCLUDED', string $subcontract = 'EXCLUDED'): array
    {
        $full = ScopeHeader::compose($materials, $subcontract, $text);
        $evidence = $this->appendAgreementEvidence($caseId, $actor->id, $full);

        return app(RecordServiceCompletionBasis::class)($actor, $key, $acceptanceId, $full, $evidence);
    }
}

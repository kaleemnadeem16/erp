<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Parties\Models\Party;
use App\Modules\Sales\Models\CustomerDecisionCase;
use App\Modules\Sales\Models\CustomerDecisionComponent;
use App\Modules\Sales\Models\QuotationFamily;
use App\Modules\Sales\Models\QuotationLogicalScope;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOperation;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOutcome;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\SalesAccess;

/**
 * Opens the shared intake case for what one customer Party sent (CA1): the verified intake identity (never a PO text alone) and, optionally, the components the customer's message
 * is about — each tied to an ORIGINAL logical quotation scope of a quotation family. It proves nothing about what was officially offered and records no consent. An intake identity
 * that already exists is never merged or reused: it is refused (`INTAKE_IDENTITY_EXISTS`) and stays pending for a person to resolve; the same request key replays the case it opened.
 * Guarded by `sales.customer-decision.evidence.record` (STAGED) and a currently active member, judged again inside the operation before any replay.
 */
final class OpenCustomerDecisionCase
{
    public function __construct(private readonly CustomerDecisionOperation $operations) {}

    /** @param  list<array<string, mixed>>  $components each: intent_identity, family_id, original_scope_anchor_id */
    public function __invoke(User $actor, ?string $requestKey, int $partyId, string $intakeIdentity, array $components = []): CustomerDecisionOutcome
    {
        $this->authorise($actor);
        $intakeIdentity = trim($intakeIdentity);
        if ($intakeIdentity === '' || mb_strlen($intakeIdentity) > 120 || count($components) > 50) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'An intake identity of 1 to 120 characters and at most 50 components are required.', 'intake_identity');
        }
        $normalised = [];
        foreach ($components as $component) {
            $identity = trim((string) ($component['intent_identity'] ?? ''));
            if ($identity === '' || mb_strlen($identity) > 120 || ! is_int($component['family_id'] ?? null) || ! is_int($component['original_scope_anchor_id'] ?? null) || isset($normalised[$identity])) {
                throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'Each component needs a distinct identity, a quotation family and an original scope.', 'components');
            }
            $normalised[$identity] = ['intent_identity' => $identity, 'family_id' => $component['family_id'], 'original_scope_anchor_id' => $component['original_scope_anchor_id']];
        }
        ksort($normalised);

        $outcome = $this->operations->run(
            $actor, 'customer_decision.open_case', $requestKey, ['party_id' => $partyId, 'intake_identity' => $intakeIdentity, 'components' => array_values($normalised)],
            function () use ($actor): null {
                $this->authorise($actor);

                return null;
            },
            function () use ($actor, $partyId, $intakeIdentity, $normalised): array {
                if (Party::query()->whereKey($partyId)->doesntExist()) {
                    throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'The customer was not found.', 'party_id');
                }
                if (CustomerDecisionCase::query()->where('intake_identity', $intakeIdentity)->exists()) {
                    throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INTAKE_IDENTITY_EXISTS, 'That intake identity already exists; it is not merged automatically.', 'intake_identity');
                }
                foreach ($normalised as $component) {
                    $this->assertOriginalScope($component['family_id'], $component['original_scope_anchor_id']);
                }
                $case = CustomerDecisionCase::create(['party_id' => $partyId, 'intake_identity' => $intakeIdentity, 'generation' => 0, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
                foreach ($normalised as $component) {
                    CustomerDecisionComponent::create($component + ['case_id' => $case->id, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
                }

                return CustomerDecisionOutcome::result($case->id, 0);
            },
        );

        return CustomerDecisionOutcome::fromReceipt($outcome['result'], $outcome['replayed']);
    }

    private function assertOriginalScope(int $familyId, int $scopeId): void
    {
        $scope = QuotationFamily::query()->whereKey($familyId)->exists()
            ? QuotationLogicalScope::query()->where('family_id', $familyId)->whereKey($scopeId)->first() : null;
        if ($scope === null || $scope->overlap_anchor_id !== null) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::INVALID_INPUT, 'A component is tied to an original scope of a quotation family.', 'components');
        }
    }

    private function authorise(User $actor): void
    {
        ActiveActor::require($actor, 'record customer decision evidence');
        SalesAccess::require($actor, PermissionVocabulary::CUSTOMER_DECISION_EVIDENCE_RECORD, 'record customer decision evidence');
    }
}

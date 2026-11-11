<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\CustomerAcceptance;
use App\Modules\Sales\Models\CustomerAcceptanceLine;
use App\Modules\Sales\Models\CustomerClaimEvent;
use App\Modules\Sales\Models\CustomerEvidencePolicyVersion;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceEligibility;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceIntent;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceReviewToken;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceSubject;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOperation;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal;

/** Full unchanged consent, lines, root/choice claims and receipt in one transaction. No target, reservation, accounting or opportunity change. STAGED; no grants. */
final class RecordCustomerAcceptance
{
    public function __construct(private readonly CustomerDecisionOperation $operations, private readonly AcceptanceEligibility $eligibility, private readonly AcceptanceReviewToken $tokens) {}

    /** @return array{acceptance_id: int, case_id: int, replayed: bool} */
    public function __invoke(User $actor, ?string $requestKey, int $revisionId, int $componentId, int $evidenceId, int $lineId, int $caseGeneration, int $scopeGeneration, ?int $choiceGeneration, string $previewToken, bool $unchangedTerms): array
    {
        AcceptanceEligibility::early($actor);
        if (! $unchangedTerms) {
            throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::ACCEPTANCE_NOT_ELIGIBLE, 'The exact issued terms must be confirmed unchanged.', 'unchanged_terms');
        }
        $intent = AcceptanceIntent::of($revisionId, $componentId, $evidenceId, $lineId, $caseGeneration, $scopeGeneration, $choiceGeneration);
        $digest = AcceptanceIntent::digest($intent);
        $outcome = $this->operations->run($actor, 'customer_decision.record_acceptance', $requestKey, $intent,
            fn (): AcceptanceSubject => $this->eligibility->lock($actor, $intent),
            function (AcceptanceSubject $s) use ($actor, $intent, $digest, $previewToken): array {
                // Business identity replay, also across people/fresh keys, precedes mutable freshness and token checks.
                $existing = CustomerAcceptance::query()->where('decision_component_id', $s->component->id)->first();
                if ($existing !== null) {
                    if ($existing->intent_digest !== $digest) {
                        throw CustomerDecisionRefusal::make(CustomerDecisionRefusal::DECISION_INTENT_CONFLICT, 'This consent component already records a different acceptance.');
                    }

                    return ['acceptance_id' => $existing->id, 'case_id' => $existing->case_id];
                }
                $this->tokens->require($actor, $digest, $previewToken);
                $this->eligibility->require($s, $intent);
                $heads = $this->eligibility->baselines($s);
                $policy = CustomerEvidencePolicyVersion::firstOrCreate(['version' => 1], ['policy_code' => 'WRITTEN_VERIFIED_FULL_ONLY_V1', 'source_requirements' => ['verbal' => false, 'verification' => ['DOCUMENT_ON_FILE', 'CONFIRMED_BY_SELLER']]]);
                $a = CustomerAcceptance::create(['case_id' => $s->case->id, 'decision_component_id' => $s->component->id, 'revision_id' => $s->revision->id,
                    'consent_evidence_id' => $intent['evidence_id'], 'policy_version_id' => $policy->id, 'actor_id' => $actor->id, 'intent_digest' => $digest]);
                CustomerAcceptanceLine::create(['acceptance_id' => $a->id, 'issued_line_id' => $s->line->id]);
                foreach ($heads as $head) {
                    CustomerClaimEvent::create(['acceptance_id' => $a->id, 'claim_head_id' => $head->id]);
                }
                $s->case->increment('generation');

                return ['acceptance_id' => $a->id, 'case_id' => $a->case_id];
            });

        return ['acceptance_id' => (int) $outcome['result']['acceptance_id'], 'case_id' => (int) $outcome['result']['case_id'], 'replayed' => $outcome['replayed']];
    }
}

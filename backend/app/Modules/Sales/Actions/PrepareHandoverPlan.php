<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\HandoverAllocation;
use App\Modules\Sales\Models\HandoverBaseline;
use App\Modules\Sales\Models\HandoverPlan;
use App\Modules\Sales\Models\HandoverReadinessEvidence;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\CustomerDecision\AcceptanceReviewToken;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionOperation;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\HandoverInput;
use App\Modules\Sales\Support\Handover\HandoverSourceRegistry;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Modules\Sales\Support\Handover\ServiceHandoverAssessment;

/** One immutable full-scope pending plan, existing decision receipts. No Job, release, acceptance change or stock/finance effect. */
final class PrepareHandoverPlan
{
    public function __construct(private readonly CustomerDecisionOperation $operations, private readonly HandoverAccess $access, private readonly ServiceHandoverAssessment $readiness, private readonly AcceptanceReviewToken $tokens) {}

    /**
     * @param  array<string,mixed>  $fields
     * @return array{plan_id:int,case_id:int,replayed:bool}
     */
    public function __invoke(User $actor, ?string $requestKey, int $acceptanceId, array $fields, string $previewToken): array
    {
        HandoverAccess::early($actor, true);
        $inputs = HandoverInput::normalise($fields);
        $digest = HandoverInput::digest($acceptanceId, $inputs);
        $outcome = $this->operations->run($actor, 'handover.prepare', $requestKey, ['acceptance_id' => $acceptanceId, 'inputs' => $inputs],
            fn (): HandoverSubject => $this->access->lock($actor, $acceptanceId),
            function (HandoverSubject $s) use ($actor, $inputs, $digest, $previewToken): array {
                $existing = HandoverPlan::where('acceptance_id', $s->acceptance->id)->first();
                if ($existing !== null) {
                    if ($existing->intent_digest !== $digest) {
                        throw R::make(R::SCOPE_ALREADY_CLAIMED, 'This acceptance already has a different full-scope handover plan.');
                    }

                    return ['plan_id' => $existing->id, 'case_id' => $s->case->id];
                }
                $state = $this->readiness->assess($s, $inputs, true);
                $this->tokens->require($actor, $state['fingerprint'], $previewToken);
                if (! $state['valid_references']) {
                    throw R::make(R::HANDOVER_NOT_READY, 'Resolve unavailable service, contact or site references before preparing the plan.');
                }
                $plan = HandoverPlan::create(['acceptance_id' => $s->acceptance->id, 'prepared_by' => $actor->id, 'path' => $inputs['path'],
                    'policy_version' => HandoverSourceRegistry::VERSION, 'intent_digest' => $digest, 'preview_token' => $previewToken, 'inputs' => $inputs]);
                $allocation = HandoverAllocation::create(['plan_id' => $plan->id, 'acceptance_line_id' => $s->acceptanceLine->id]);
                $stage = HandoverStage::create(['allocation_id' => $allocation->id]);
                HandoverBaseline::create(['stage_id' => $stage->id, 'issued_line_id' => $s->line->id, 'contact_id' => $inputs['contact_id'], 'site_id' => $inputs['site_id'], 'operational_snapshot' => $state['operational']]);
                HandoverReadinessEvidence::create(['stage_id' => $stage->id, 'assessment' => $state['assessment']]);

                return ['plan_id' => $plan->id, 'case_id' => $s->case->id];
            });

        return ['plan_id' => (int) $outcome['result']['plan_id'], 'case_id' => (int) $outcome['result']['case_id'], 'replayed' => $outcome['replayed']];
    }
}

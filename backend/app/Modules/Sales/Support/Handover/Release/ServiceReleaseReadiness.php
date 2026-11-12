<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Handover\Release;

use App\Modules\Sales\Models\CustomerScopeClaimHead;
use App\Modules\Sales\Models\HandoverBaseline;
use App\Modules\Sales\Models\HandoverPlan;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\Handover\HandoverSubject;
use App\Modules\Sales\Support\Handover\ServiceHandoverAssessment;
use App\Modules\Sales\Support\Kernel\OperationIntent;

/**
 * Given a prepared stage and the CURRENT policy, what blocks its release, with the supported source facts it relied on. Runs INSIDE the release transaction after the
 * source rows are locked. The pending preparation evidence is never rewritten: this is a fresh evaluation, and UNKNOWN (unproven) is distinct from evidenced NOT_APPLICABLE.
 */
final class ServiceReleaseReadiness
{
    /** The preparation facts that block a release when missing; the other preparation gaps are answered by the basis, the cost policy, the applicability proof and the bound receiver. */
    private const FACT_BLOCKERS = ['SERVICE_SOURCE_NOT_AVAILABLE', 'SERVICE_LOCATION_REQUIRED', 'SERVICE_CONTACT_REQUIRED', 'SERVICE_SITE_REQUIRED'];

    public function __construct(
        private readonly ServiceHandoverAssessment $facts,
        private readonly ReleaseCostPolicy $cost,
        private readonly SourceApplicabilityProof $applicability,
        private readonly AuthorityFence $fence,
    ) {}

    public function assess(HandoverSubject $subject, HandoverStage $stage, HandoverBaseline $baseline, ?ResolvedCompletionBasis $basis): ReleaseReadiness
    {
        $plan = HandoverPlan::query()->where('acceptance_id', $subject->acceptance->id)->firstOrFail();
        $state = $this->facts->assess($subject, $plan->inputs);
        $blockers = [];
        $next = [];
        foreach ($state['assessment']['missing'] as $i => $code) {
            if (in_array($code, self::FACT_BLOCKERS, true)) {
                $blockers[] = $code;
                $next[] = $state['assessment']['next_actions'][$i];
            }
        }
        if ($basis === null) {
            $blockers[] = 'COMPLETION_BASIS_MISSING';
            $next[] = ['fact' => 'COMPLETION_BASIS_MISSING', 'owner' => 'Sales', 'action' => 'Record the customer-agreed completion conditions, citing written consent evidence.'];
        }
        $cost = $this->cost->requirementFor($subject);
        if (! $cost->satisfied()) {
            $blockers[] = 'SERVICE_COST_EVIDENCE_REQUIRED';
            $next[] = ['fact' => 'SERVICE_COST_EVIDENCE_REQUIRED', 'owner' => 'Sales', 'action' => 'A mandatory commercial control needs cost evidence from a supported source.'];
        }
        $applicability = $this->applicability->evaluate();
        if ($applicability['status'] !== 'NOT_APPLICABLE') {
            $blockers[] = 'SOURCE_APPLICABILITY_UNPROVEN';
            $next[] = ['fact' => 'SOURCE_APPLICABILITY_UNPROVEN', 'owner' => 'Inventory', 'action' => 'The Job lineage is referenced by another module; applicability cannot be proved.'];
        }
        $relied = [
            'authority_head_generation' => $this->fence->lock(),
            'claim_heads' => CustomerScopeClaimHead::query()->where('family_id', $subject->line->family_id)->orderBy('id')->get()->mapWithKeys(fn ($h): array => [(string) $h->id => $h->generation])->all(),
            'completion_basis' => $basis === null ? null : ['id' => $basis->basisId, 'version' => $basis->version],
            'applicability' => ['status' => $applicability['status'], 'version' => $applicability['version']],
            'preparation_fingerprint' => $state['fingerprint'],
        ];
        $fingerprint = OperationIntent::hash('handover.release.review', ['stage' => $stage->id, 'baseline' => $baseline->id, 'relied' => $relied, 'blockers' => $blockers,
            'cost' => [$cost->required, $cost->status, $cost->policyVersion]]);

        return new ReleaseReadiness($blockers, $next, $cost, $applicability, $relied, $fingerprint);
    }
}

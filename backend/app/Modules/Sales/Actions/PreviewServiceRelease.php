<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Models\HandoverBaseline;
use App\Modules\Sales\Models\HandoverStage;
use App\Modules\Sales\Support\CustomerDecision\CustomerDecisionRefusal as R;
use App\Modules\Sales\Support\Handover\HandoverAccess;
use App\Modules\Sales\Support\Handover\Release\ReleaseLineage;
use App\Modules\Sales\Support\Handover\Release\SalesCompletionBasis;
use App\Modules\Sales\Support\Handover\Release\ServiceReleaseReadiness;
use App\Modules\Sales\Support\PermissionVocabulary as P;
use Illuminate\Support\Facades\DB;

/** Read-only review of what blocks releasing one prepared stage, under the same locks and duty as the release. Writes nothing; returns the fingerprint a release must carry. */
final class PreviewServiceRelease
{
    public function __construct(private readonly HandoverAccess $access, private readonly ServiceReleaseReadiness $readiness, private readonly SalesCompletionBasis $bases) {}

    /** @return array{ready:bool,blockers:list<string>,next_actions:list<array<string,string>>,cost:array<string,mixed>,applicability:array<string,mixed>,basis_id:int|null,basis_version:int|null,fingerprint:string} */
    public function __invoke(User $actor, int $stageId): array
    {
        HandoverAccess::early($actor, false, P::HANDOVER_RELEASE);
        $acceptanceId = ReleaseLineage::acceptanceIdOfStage($stageId) ?? throw R::make(R::ACCEPTANCE_NOT_FOUND, 'The handover stage was not found.');

        return DB::transaction(function () use ($actor, $stageId, $acceptanceId): array {
            $s = $this->access->lock($actor, $acceptanceId, false, P::HANDOVER_RELEASE);
            $stage = HandoverStage::query()->findOrFail($stageId);
            $baseline = HandoverBaseline::query()->where('stage_id', $stage->id)->firstOrFail();
            $basis = $this->bases->find($s->acceptanceLine);
            $r = $this->readiness->assess($s, $stage, $baseline, $basis);

            return ['ready' => $r->ready(), 'blockers' => $r->blockers, 'next_actions' => $r->nextActions,
                'cost' => ['required' => $r->cost->required, 'status' => $r->cost->status, 'policy_version' => $r->cost->policyVersion], 'applicability' => $r->applicability,
                'basis_id' => $basis?->basisId, 'basis_version' => $basis?->version, 'fingerprint' => $r->fingerprint];
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Sales\Models\QuotationDraftLine;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\MaterialPlan\MaterialPlanStanding;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\ActionAuthorization;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Support\Facades\DB;

/**
 * Sends the approvals an adopted Material Plan needs to the shared approval engine (ADR 0070, ADR 0054 §7, ADR 0076): one request per controlled item and kind (material variance,
 * hand-chosen stock), routed on the facts frozen with the adoption. An approval-required band is never read as "not required": if the engine has no policy that applies (or approval
 * is switched off) the request is refused and nothing is submitted. Asking again returns the open request unchanged. The person who asks is not thereby allowed to approve — that
 * is the engine's separate, independent decision. Guarded by `sales.quotation.prepare` for the revision and `estimating.material_variance.request`.
 */
final class RequestQuotationMaterialPlanApproval
{
    public function __construct(private readonly MaterialPlanStanding $standing, private readonly SubmitForApproval $submit) {}

    /** @return int how many approval requests are now open for the plan (new or already open) */
    public function __invoke(User $actor, int $revisionId, int $lineId): int
    {
        return DB::transaction(function () use ($actor, $revisionId, $lineId): int {
            app(CompanyAccessFence::class)->lockAll();
            $actor = ActiveActor::require($actor, 'request material plan approval');
            ActionAuthorization::require($actor, 'estimating.material_variance.request', 'request approval of a material plan');
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE], true);
            if (! $revision->isDraft()) {
                throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can send a material plan for approval.', 'revision_id');
            }
            $line = QuotationDraftLine::query()->where('revision_id', $revision->id)->whereKey($lineId)->first() ?? throw QuotationRefusal::make(QuotationRefusal::LINE_NOT_FOUND, 'The line is not on this draft.', 'line_id');
            $standing = $this->standing->forLine($line);
            $plan = $standing['plan'] ?? throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_NOT_FOUND, 'The line has no adopted material plan.', 'line_id');
            if ($standing['state'] === MaterialPlanStanding::STALE) {
                throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_STALE, 'The plan no longer describes the line; adopt it again before asking for approval.', 'line_id');
            }
            $rows = $this->standing->approvals($plan);
            if ($rows->isEmpty()) {
                throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_NOTHING_TO_APPROVE, 'No control on this plan needs an approval.', 'line_id');
            }
            $open = 0;
            foreach ($rows as $row) {
                if ($this->standing->approvalState($row) === MaterialPlanStanding::READY) {
                    $open++;

                    continue;
                }
                if (($this->submit)($row->subject_code, $row->id, $actor) === null) {
                    throw QuotationRefusal::make(QuotationRefusal::MATERIAL_PLAN_APPROVAL_POLICY_MISSING, 'No approval policy applies to this plan, or approval is not switched on for it; an approval-required control is never treated as approved.', 'line_id');
                }
                $open++;
            }

            return $open;
        });
    }
}

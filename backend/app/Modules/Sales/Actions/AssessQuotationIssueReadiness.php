<?php

declare(strict_types=1);

namespace App\Modules\Sales\Actions;

use App\Models\User;
use App\Modules\Sales\Support\DiscountAuthority\AuthorityFence;
use App\Modules\Sales\Support\Kernel\ActiveActor;
use App\Modules\Sales\Support\Kernel\SalesActorContext;
use App\Modules\Sales\Support\PermissionVocabulary;
use App\Modules\Sales\Support\Quotation\Issue\QuotationIssueAssessor;
use App\Modules\Sales\Support\Quotation\QuotationAccess;
use App\Modules\Sales\Support\Quotation\QuotationRefusal;
use App\Support\Scope\CompanyAccessFence;
use Illuminate\Support\Facades\DB;

/**
 * Whether a draft could be issued today, as a list of named blockers (ADR 0123, QL3). Read-only: it allocates no number, writes nothing, and answers for the preparer
 * (`sales.quotation.prepare`, STAGED; a current ACTIVE member) with codes only — no amount, cost, rate or free text. The decision is the issue assessor's, the very one
 * `IssueQuotation` re-runs under lock: header facts, every line through the preview the preparer already sees (price entered, net complete, FX evidence unchanged, rounding policy
 * unchanged), then the two independent evidence providers — tax (FR3) and mandatory controls (FR4) — which report TAX_NOT_DETERMINED / CONTROLS_NOT_CONFIGURED until real
 * implementations are bound. Unknown is never a pass.
 */
final class AssessQuotationIssueReadiness
{
    public function __construct(private readonly QuotationIssueAssessor $assessor) {}

    /** @return array{revision_id: int, input_generation: int, issue_ready: bool, blockers: list<array{code: string, scope: string, line_id: int|null}>} */
    public function __invoke(User $actor, int $revisionId): array
    {
        ActiveActor::require($actor, 'assess a quotation for issue');
        $context = SalesActorContext::forUser($actor, 'quotation.assess_issue_readiness');

        // One read transaction holds the company access fence (shared) through the record check and the whole assessment.
        return DB::transaction(function () use ($actor, $revisionId, $context): array {
            app(CompanyAccessFence::class)->lockAll();
            app(AuthorityFence::class)->lock();
            $actor = ActiveActor::require($actor, 'assess a quotation for issue');
            $revision = app(QuotationAccess::class)->requireRevision($actor, $revisionId, [PermissionVocabulary::QUOTATION_VIEW, PermissionVocabulary::QUOTATION_PREPARE]);
            if (! $revision->isDraft()) {
                throw QuotationRefusal::make(QuotationRefusal::QUOTATION_NOT_EDITABLE, 'Only a draft quotation can be assessed for issue.', 'revision_id');
            }
            $assessment = $this->assessor->assess($actor, $context, $revision);

            return ['revision_id' => $revision->id, 'input_generation' => $revision->input_generation, 'issue_ready' => $assessment->ready(),
                'blockers' => array_map(fn ($blocker): array => $blocker->toArray(), $assessment->blockers)];
        });
    }
}

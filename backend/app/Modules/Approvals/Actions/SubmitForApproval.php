<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalStepStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Approvals\Support\Review\ApprovalReviewRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Routes a document into approval (ADR 0070). Returns the new request, or null when the company's mode says none is
 * needed (mode NONE, or POLICY with no matching policy). Mode REQUIRED with no matching policy refuses: a missing
 * configuration is never read as "approved".
 *
 * The routing facts, their hash and the pinned currency evidence are frozen on the request, and so is the policy
 * version, which stays explainable after a later version is activated. Resubmitting while a request is open
 * returns that request unchanged if nothing changed, and refuses otherwise.
 */
final class SubmitForApproval
{
    public function __construct(
        private readonly ApprovalSubjectRegistry $subjects,
        private readonly ApprovalModes $modes,
        private readonly ResolveApprovalPolicy $resolve,
        private readonly OpenApprovalStage $openStage,
    ) {}

    public function __invoke(string $subjectType, int $subjectId, User $actor): ?ApprovalRequest
    {
        $mode = $this->modes->for($subjectType);
        if ($mode === ApprovalMode::None) {
            return null;
        }

        return DB::transaction(function () use ($subjectType, $subjectId, $actor, $mode): ?ApprovalRequest {
            $routing = $this->subjects->get($subjectType)->routing($subjectId);
            $policy = ($this->resolve)($subjectType, $routing);
            if ($policy === null) {
                if ($mode === ApprovalMode::Required) {
                    throw new DomainInvariantException('This requires approval but no approval policy applies to it. Ask an administrator to configure one.', 'approval_policy');
                }

                return null;
            }

            $open = ApprovalRequest::query()->where('subject_type', $subjectType)->where('subject_id', $subjectId)
                ->where('status', ApprovalRequestStatus::Pending)->lockForUpdate()->first();
            if ($open !== null) {
                if ($open->facts_hash === $routing->hash() && app(ApprovalReviewRegistry::class)->unchanged($open)) {
                    return $open;
                }
                throw new DomainInvariantException('This already has an approval in progress for different figures. Withdraw it first.', 'approval');
            }

            return $this->create($policy, $subjectType, $subjectId, $routing, $actor);
        });
    }

    private function create(ApprovalPolicy $policy, string $subjectType, int $subjectId, ApprovalRouting $routing, User $actor): ApprovalRequest
    {
        $snapshot = $routing->snapshot();
        $review = app(ApprovalReviewRegistry::class)->capture($subjectType, $subjectId);
        if ($review !== null) {
            $snapshot['document_review'] = $review;
        }
        $request = ApprovalRequest::query()->create([
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'policy_id' => $policy->id,
            'status' => ApprovalRequestStatus::Pending, 'submitted_by' => $actor->id, 'submitted_at' => now(),
            'facts' => $snapshot, 'facts_hash' => $routing->hash(), 'summary' => $routing->summary,
        ]);
        foreach ($policy->steps as $step) {
            $request->steps()->create([
                'review_profile' => $step->review_profile->value, 'policy_step_id' => $step->id, 'stage' => $step->stage, 'name' => $step->name,
                'required_approvals' => $step->required_approvals, 'status' => ApprovalStepStatus::Waiting,
            ]);
        }
        if ($request->steps()->count() === 0) {
            throw new DomainInvariantException('The matching approval policy has no steps.', 'approval_policy');
        }
        ($this->openStage)($request, (int) $request->steps()->min('stage'));

        return $request->refresh()->load('steps');
    }
}

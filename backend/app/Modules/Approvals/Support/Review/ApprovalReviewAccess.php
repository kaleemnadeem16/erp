<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support\Review;

use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalInbox;
use App\Modules\Approvals\Support\ApprovalRequestAccess;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Support\CompanyContext;

final class ApprovalReviewAccess
{
    public function __construct(private readonly ApprovalReviewRegistry $reviews, private readonly ApprovalSubjectRegistry $subjects, private readonly ApprovalInbox $inbox) {}

    public function profile(?User $actor, ApprovalRequest $request): ?ReviewProfile
    {
        if ($actor === null || $actor->status !== MemberStatus::Active || $actor->must_change_password || $actor->company_id !== $request->company_id
            || $request->company_id !== app(CompanyContext::class)->id() || ! $this->reviews->available($request) || ! app(ApprovalRequestAccess::class)->canView($actor, $request)) {
            return null;
        }
        if ($this->subjects->has($request->subject_type) && $this->subjects->get($request->subject_type)->canViewEvidence($actor)) {
            return ReviewProfile::Commercial;
        }
        $profiles = [];
        if ($request->isOpen()) {
            foreach ($this->inbox->actionableSteps($actor, $request) as $step) {
                $profiles[] = $step->review_profile;
            }
        }
        $request->loadMissing('steps.decisions');
        foreach ($request->steps->flatMap->decisions as $decision) {
            if (in_array($actor->id, [$decision->decided_by, $decision->principal_id], true)) {
                $profiles[] = ReviewProfile::tryFrom($decision->authority_snapshot['review_profile'] ?? '');
            }
        }

        return in_array(ReviewProfile::Commercial, $profiles, true) ? ReviewProfile::Commercial
            : (in_array(ReviewProfile::Operational, $profiles, true) ? ReviewProfile::Operational : null);
    }
}

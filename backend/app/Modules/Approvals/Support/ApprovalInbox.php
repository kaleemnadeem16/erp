<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Models\ApprovalRequestStep;
use Illuminate\Support\Collection;

/**
 * What a person can decide right now: open steps of pending requests where they hold the authority — their own or a
 * delegator's — and have not already filled a slot. Pending requests are few per company, so this filters in PHP
 * with the same eligibility the decision itself uses; there is no second, drifting definition.
 */
final class ApprovalInbox
{
    public function __construct(private readonly ApprovalEligibility $eligibility) {}

    /** @return Collection<int, ApprovalRequest> */
    public function forUser(User $user): Collection
    {
        return ApprovalRequest::query()->where('status', ApprovalRequestStatus::Pending)
            ->with(['steps.policyStep', 'steps.decisions', 'submitter'])->orderBy('submitted_at')->get()
            ->filter(fn (ApprovalRequest $request): bool => $this->actionableStep($user, $request) !== null)
            ->values();
    }

    public function actionableStep(User $user, ApprovalRequest $request): ?ApprovalRequestStep
    {
        return $this->actionableSteps($user, $request)->first();
    }

    /** @return Collection<int, ApprovalRequestStep> */
    public function actionableSteps(User $user, ApprovalRequest $request): Collection
    {
        $steps = collect();
        foreach ($request->steps->where('status', ApprovalStepStatus::Open) as $step) {
            $grant = $this->eligibility->grantFor($user, $request, $step);
            if ($grant === null) {
                continue;
            }
            $taken = $step->decisions->contains(fn ($d): bool => in_array($user->id, [$d->decided_by, $d->principal_id], true) || in_array($grant->principal->id, [$d->decided_by, $d->principal_id], true));
            if (! $taken && ($step->policyStep->allow_submitter || ! in_array($request->submitted_by, [$user->id, $grant->principal->id], true))) {
                $steps->push($step);
            }
        }

        return $steps;
    }
}

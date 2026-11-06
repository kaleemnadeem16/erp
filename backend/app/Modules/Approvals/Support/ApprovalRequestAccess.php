<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ApprovalRequestAccess
{
    public function __construct(private readonly ApprovalInbox $inbox) {}

    public function canView(User $user, ApprovalRequest $request): bool
    {
        if ($user->company_id !== $request->company_id || $request->company_id !== app(CompanyContext::class)->id()) {
            return false;
        }
        $request->loadMissing('steps.decisions');

        return $request->submitted_by === $user->id || $user->can('approvals.history.view')
            || $request->steps->flatMap->decisions->contains(fn ($decision): bool => in_array($user->id, [$decision->decided_by, $decision->principal_id], true))
            || ($request->isOpen() && $this->inbox->actionableStep($user, $request) !== null);
    }

    /** An approvals manager may withdraw any request of the company, so for withdrawing they may also know it exists. */
    public function canViewOrWithdraw(User $user, ApprovalRequest $request): bool
    {
        return $this->canView($user, $request)
            || ($user->company_id === $request->company_id && $request->company_id === app(CompanyContext::class)->id() && $user->can('approvals.policy.manage'));
    }

    /**
     * The non-disclosing refusal: a request the caller may not see answers exactly like an id that does not exist
     * (the same exception route-model binding throws), so existence is never revealed. Call it before validating
     * input or running any action, so a hidden request cannot be told apart by what comes next.
     */
    public function hide(ApprovalRequest $request): never
    {
        throw (new ModelNotFoundException)->setModel(ApprovalRequest::class, [(string) $request->getKey()]);
    }
}

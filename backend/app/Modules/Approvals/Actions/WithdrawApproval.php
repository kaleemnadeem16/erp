<?php

declare(strict_types=1);

namespace App\Modules\Approvals\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use Illuminate\Support\Facades\DB;

/** The submitter (or a policy manager) takes a pending request back so the document can be edited and resubmitted. */
final class WithdrawApproval
{
    public function __construct(private readonly InvalidateApprovals $invalidate) {}

    public function __invoke(ApprovalRequest $request, User $actor, string $reason): ApprovalRequest
    {
        if (trim($reason) === '') {
            throw new DomainInvariantException('Say why the approval is being withdrawn.', 'reason');
        }

        return DB::transaction(function () use ($request, $actor, $reason): ApprovalRequest {
            $request = ApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $request->isOpen()) {
                throw new DomainInvariantException("This approval is already {$request->status->value}.", 'status');
            }
            if ($request->submitted_by !== $actor->id && ! $actor->can('approvals.policy.manage')) {
                throw new DomainInvariantException('Only the person who submitted this, or an approvals manager, can withdraw it.', 'submitted_by');
            }

            return ($this->invalidate)($request, trim($reason), ApprovalRequestStatus::Withdrawn);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Modules\Approvals\Actions\ResolveApprovalPolicy;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalMode;
use App\Modules\Approvals\Support\ApprovalModes;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Approvals\Support\ApprovalSubjectRegistry;
use App\Modules\Purchasing\Models\PurchaseOrder;

/**
 * Where a purchase order stands with the approval engine, for its detail screen: whether approval applies at all, the
 * latest request, and whether an earlier approval no longer matches the order as it is now.
 */
final class PurchaseOrderApprovalState
{
    public function __construct(
        private readonly ApprovalModes $modes,
        private readonly ApprovalSubjectRegistry $subjects,
        private readonly ResolveApprovalPolicy $resolve,
    ) {}

    /** @return array{mode: string, required: bool, status: string, request_id: int|null, changed_since_approval: bool} */
    public function for(PurchaseOrder $order): array
    {
        $mode = $this->modes->for(PurchaseOrderApprovalSubject::CODE);
        $latest = ApprovalRequest::query()->where('subject_type', PurchaseOrderApprovalSubject::CODE)->where('subject_id', $order->id)->orderByDesc('id')->first();
        $required = false;
        $changed = false;
        if ($mode !== ApprovalMode::None && $order->status === PurchaseOrderStatus::Draft) {
            $routing = $this->subjects->get(PurchaseOrderApprovalSubject::CODE)->routing($order->id);
            $required = $mode === ApprovalMode::Required || ($this->resolve)(PurchaseOrderApprovalSubject::CODE, $routing) !== null;
            $changed = $latest !== null && $latest->status === ApprovalRequestStatus::Approved && $latest->facts_hash !== $routing->hash();
        }

        $status = match (true) {
            $latest === null => $required ? 'NOT_SUBMITTED' : 'NOT_REQUIRED',
            $changed => 'INVALIDATED',
            default => $latest->status->value,
        };
        if ($latest !== null && ! $required && $order->status === PurchaseOrderStatus::Draft && $latest->status !== ApprovalRequestStatus::Pending) {
            $status = $latest->status === ApprovalRequestStatus::Approved && ! $changed ? 'APPROVED' : 'NOT_REQUIRED';
        }

        return ['mode' => $mode->value, 'required' => $required, 'status' => $status, 'request_id' => $latest?->id, 'changed_since_approval' => $changed];
    }
}

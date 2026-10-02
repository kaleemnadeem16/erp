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
    public function for(PurchaseOrder $order, ?ApprovalMode $mode = null, ?ApprovalRequest $latest = null, bool $latestKnown = false): array
    {
        $mode ??= $this->modes->for(PurchaseOrderApprovalSubject::CODE);
        if (! $latestKnown) {
            $latest = ApprovalRequest::query()->where('subject_type', PurchaseOrderApprovalSubject::CODE)->where('subject_id', $order->id)->orderByDesc('id')->first();
        }
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

    /**
     * The approval status of every order on a list page, for its Approval column — the same answer {@see for()} gives one
     * order, with the latest requests fetched in a single query instead of one per row. Null for every order while the
     * company has approvals switched off, so a screen can tell "approvals are not in use" from "not required".
     *
     * @param  iterable<PurchaseOrder>  $orders
     * @return array<int, string|null> order id => status
     */
    public function statusesFor(iterable $orders): array
    {
        $orders = collect($orders);
        $mode = $this->modes->for(PurchaseOrderApprovalSubject::CODE);
        if ($mode === ApprovalMode::None) {
            return $orders->mapWithKeys(fn (PurchaseOrder $order): array => [$order->id => null])->all();
        }

        $latest = ApprovalRequest::query()
            ->where('subject_type', PurchaseOrderApprovalSubject::CODE)
            ->whereIn('subject_id', $orders->pluck('id'))
            ->orderBy('id')
            ->get()
            ->keyBy('subject_id'); // ascending id, so the last request per order wins

        return $orders->mapWithKeys(fn (PurchaseOrder $order): array => [
            $order->id => $this->for($order, $mode, $latest->get($order->id), true)['status'],
        ])->all();
    }
}

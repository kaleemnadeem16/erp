<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\SubmitForApproval;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * Sends a draft purchase order for approval (ADR 0070). Returns the approval request, or null when the company's
 * approval mode says none is needed — in which case the order can simply be issued. The order is frozen while its
 * request is open: editing needs the request withdrawn first.
 */
final class SubmitPurchaseOrder
{
    public function __construct(private readonly SubmitForApproval $submit) {}

    public function __invoke(PurchaseOrder $order, User $actor): ?ApprovalRequest
    {
        if (! $actor->can('purchasing.purchase-order.submit')) {
            throw new DomainInvariantException('The actor is not permitted to submit a purchase order for approval.', 'submitted_by');
        }

        return DB::transaction(function () use ($order, $actor): ?ApprovalRequest {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft || ! $order->lines()->exists()) {
                throw new DomainInvariantException('Only a non-empty draft purchase order can be submitted for approval.', 'status');
            }

            return ($this->submit)(PurchaseOrderApprovalSubject::CODE, $order->id, $actor);
        });
    }
}

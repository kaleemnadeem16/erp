<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\ActionAuthorization;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdatePurchaseOrder
{
    public function __construct(
        private readonly BuildPurchaseOrderLineAttributes $buildLine,
        private readonly InvalidateApprovals $invalidateApprovals,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(PurchaseOrder $order, array $attributes, User $actor): PurchaseOrder
    {
        ActionAuthorization::require($actor, 'purchasing.purchase-order.create', 'edit a purchase order');

        return DB::transaction(function () use ($order, $attributes): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new DomainInvariantException('Only a draft purchase order can be edited.', 'status');
            }
            if (ApprovalRequest::query()->where('subject_type', PurchaseOrderApprovalSubject::CODE)->where('subject_id', $order->id)
                ->where('status', ApprovalRequestStatus::Pending)->exists()) {
                throw new DomainInvariantException('This order is waiting for approval and cannot be edited. Withdraw the approval request first.', 'approval');
            }
            // Whatever was approved was approved for the order as it was; any edit means it is approved again or not at all.
            $this->invalidateApprovals->forSubject(PurchaseOrderApprovalSubject::CODE, $order->id, 'The order was edited after it was approved.');
            $lines = Arr::pull($attributes, 'lines');
            $order->fill($attributes);
            $baseAmountsChanged = $order->isDirty(['currency_id', 'fx_rate']);
            $order->save();
            if (is_array($lines)) {
                $order->lines()->delete();
                foreach ($lines as $line) {
                    $order->lines()->create(($this->buildLine)($order, $line));
                }
            } elseif ($baseAmountsChanged) {
                $fx = BigDecimal::of($order->fx_rate);
                foreach ($order->lines()->lockForUpdate()->get() as $line) {
                    $line->update([
                        'base_unit_price' => (string) BigDecimal::of($line->unit_price)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                        'base_discount_amount' => (string) BigDecimal::of($line->discount_amount)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                        'base_tax_amount' => (string) BigDecimal::of($line->tax_amount)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                        'base_line_amount' => (string) BigDecimal::of($line->line_amount)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                    ]);
                }
            }

            return $order->refresh()->load('lines');
        });
    }
}

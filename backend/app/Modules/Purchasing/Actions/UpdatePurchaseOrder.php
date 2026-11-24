<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Approvals\Actions\InvalidateApprovals;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalRequestStatus;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderAccess;
use App\Modules\Purchasing\Support\PurchaseOrderApprovalSubject;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdatePurchaseOrder
{
    public function __construct(
        private readonly BuildPurchaseOrderLineAttributes $buildLine,
        private readonly InvalidateApprovals $invalidateApprovals,
        private readonly ReconcilePurchaseOrderFx $reconcileFx,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(PurchaseOrder $order, array $attributes, User $actor): PurchaseOrder
    {
        ActionAuthorization::require($actor, 'purchasing.purchase-order.create', 'edit a purchase order');

        return DB::transaction(function () use ($order, $attributes, $actor): PurchaseOrder {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'purchasing.purchase-order.create', 'edit a purchase order');
            $access = PurchaseOrderAccess::make();
            $order = $access->lockForDuty($actor, $order->id, 'purchasing.purchase-order.create');
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
            // A location only means something inside its warehouse: clearing the warehouse clears it too.
            if (array_key_exists('ship_to_warehouse_id', $attributes) && $attributes['ship_to_warehouse_id'] === null) {
                $attributes['ship_to_location_id'] = null;
            }
            // Changing the warehouse without naming a location must not leave the old warehouse's location behind.
            if (($attributes['ship_to_warehouse_id'] ?? $order->ship_to_warehouse_id) !== $order->ship_to_warehouse_id && ! array_key_exists('ship_to_location_id', $attributes)) {
                $attributes['ship_to_location_id'] = null;
            }
            $order->fill($attributes);
            if ($order->isDirty(['ship_to_warehouse_id', 'buyer_user_id'])) {
                $access->requireReachable($actor, 'purchasing.purchase-order.create', $order->created_by, $order->ship_to_warehouse_id, $order->buyer_user_id);
            }
            if ($order->isDirty(['currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_policy', 'fx_override_reason'])) {
                // A changed rate is checked against the governed rate exactly as a new order's is (issue #90); an unrelated edit is not re-litigated.
                $order->fill(($this->reconcileFx)($order->only(['currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_policy', 'fx_override_reason']), $actor));
            }
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

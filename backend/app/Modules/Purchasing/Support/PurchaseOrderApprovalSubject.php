<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Approvals\Models\ApprovalRequest;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\RecordScopedEvidenceSubject;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How a purchase order tells the approval engine what it is (ADR 0070). The routed amount is the order total in the
 * company base currency, converted at the order's own pinned rate — the same rate that priced its lines — so an
 * approval never moves when a rate does, and the original amount, currency, rate, date and source travel with it.
 */
final class PurchaseOrderApprovalSubject implements RecordScopedEvidenceSubject
{
    public const CODE = 'PURCHASE_ORDER';

    public function code(): string
    {
        return self::CODE;
    }

    public function label(): string
    {
        return 'Purchase order';
    }

    public function approvePermission(): string
    {
        return 'purchasing.purchase-order.approve';
    }

    public function facts(): array
    {
        return [
            'amount_base' => ['label' => 'Order total (base currency)', 'type' => ApprovalFactType::Decimal],
            'supplier_account_id' => ['label' => 'Supplier', 'type' => ApprovalFactType::Id],
            'currency_id' => ['label' => 'Order currency', 'type' => ApprovalFactType::Id],
        ];
    }

    /** The amount and its currency evidence are the order's commercial facts: whoever may see those on the order may see them here. */
    public function canViewEvidence(User $user): bool
    {
        return PurchasingCommercialAccess::canView($user);
    }

    /** The capability must also reach this order: a limited commercial grant never shows the money of an order it does not reach. */
    public function evidenceInReach(User $user, ApprovalRequest $request): bool
    {
        $order = PurchaseOrder::query()->find($request->subject_id);

        return $order !== null && PurchasingCommercialAccess::forOrder($user, $order);
    }

    public function routing(int $subjectId): ApprovalRouting
    {
        $order = PurchaseOrder::query()->with(['lines', 'currency', 'supplierAccount.party'])->findOrFail($subjectId);
        $sum = fn (string $column): BigDecimal => $order->lines->reduce(fn (BigDecimal $t, $line): BigDecimal => $t->plus((string) $line->{$column}), BigDecimal::zero())->toScale(4, RoundingMode::HALF_UP);
        $base = (string) $sum('base_line_amount');

        // Delivery, ownership and terms facts travel with the approval only when the order states them, so an order approved before they existed keeps the fingerprint it was approved on.
        // Payment terms count when the order's own override stands (a draft override, or an issued order whose frozen terms came from it); terms that merely follow the supplier do not.
        $own = $order->payment_terms_source === null ? $order->payment_terms_days : ($order->payment_terms_source === 'PURCHASE_ORDER' ? $order->payment_terms_days : null);
        $stated = array_filter([
            'ship_to_warehouse_id' => $order->ship_to_warehouse_id, 'ship_to_location_id' => $order->ship_to_location_id, 'buyer_user_id' => $order->buyer_user_id,
            'payment_terms_days' => $own, 'shipping_method' => $order->shipping_method,
        ], fn ($value): bool => $value !== null);

        return new ApprovalRouting(
            ['supplier_account_id' => $order->supplier_account_id, 'currency_id' => $order->currency_id] + $stated,
            $base,
            [
                'original_amount' => (string) $sum('line_amount'), 'currency_code' => $order->currency->code,
                'fx_rate' => $order->fx_rate, 'fx_rate_date' => $order->fx_rate_date->toDateString(),
                'fx_rate_type' => $order->fx_rate_type, 'fx_rate_source' => $order->fx_rate_source, 'base_amount' => $base,
                'fx_governed_rate' => $order->fx_governed_rate, 'fx_override_reason' => $order->fx_override_reason,
            ],
            ['number' => $order->number ?? "Draft #{$order->id}", 'supplier' => $order->supplierAccount->party->name ?? $order->supplierAccount->code, 'order_date' => $order->order_date->toDateString()],
        );
    }
}

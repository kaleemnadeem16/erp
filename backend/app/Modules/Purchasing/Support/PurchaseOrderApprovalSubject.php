<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Models\User;
use App\Modules\Approvals\Support\ApprovalFactType;
use App\Modules\Approvals\Support\ApprovalRouting;
use App\Modules\Approvals\Support\ApprovalSubject;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How a purchase order tells the approval engine what it is (ADR 0070). The routed amount is the order total in the
 * company base currency, converted at the order's own pinned rate — the same rate that priced its lines — so an
 * approval never moves when a rate does, and the original amount, currency, rate, date and source travel with it.
 */
final class PurchaseOrderApprovalSubject implements ApprovalSubject
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

    public function routing(int $subjectId): ApprovalRouting
    {
        $order = PurchaseOrder::query()->with(['lines', 'currency', 'supplierAccount.party'])->findOrFail($subjectId);
        $sum = fn (string $column): BigDecimal => $order->lines->reduce(fn (BigDecimal $t, $line): BigDecimal => $t->plus((string) $line->{$column}), BigDecimal::zero())->toScale(4, RoundingMode::HALF_UP);
        $base = (string) $sum('base_line_amount');

        return new ApprovalRouting(
            ['supplier_account_id' => $order->supplier_account_id, 'currency_id' => $order->currency_id],
            $base,
            [
                'original_amount' => (string) $sum('line_amount'), 'currency_code' => $order->currency->code,
                'fx_rate' => $order->fx_rate, 'fx_rate_date' => $order->fx_rate_date->toDateString(),
                'fx_rate_type' => $order->fx_rate_type, 'fx_rate_source' => $order->fx_rate_source, 'base_amount' => $base,
            ],
            ['number' => $order->number ?? "Draft #{$order->id}", 'supplier' => $order->supplierAccount->party->name ?? $order->supplierAccount->code, 'order_date' => $order->order_date->toDateString()],
        );
    }
}

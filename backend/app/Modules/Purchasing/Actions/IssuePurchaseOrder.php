<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Contracts\NumberingScheme;
use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;

final class IssuePurchaseOrder
{
    public function __construct(
        private readonly NumberingScheme $numbers,
        private readonly ResolvePurchasingSettings $resolveSettings,
    ) {}

    public function __invoke(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        $canCommitCutoverOrder = $order->inventory_cutover_id !== null
            && $actor->can('purchasing.cutover.commit');

        if (! $actor->can('purchasing.purchase-order.issue') && ! $canCommitCutoverOrder) {
            throw new DomainInvariantException('The actor is not permitted to issue a purchase order.', 'issued_by');
        }

        return DB::transaction(function () use ($order): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft || ! $order->lines()->exists()) {
                throw new DomainInvariantException('Only a non-empty draft purchase order can be issued.', 'status');
            }
            $settings = ($this->resolveSettings)();
            foreach ($order->lines()->with('item')->lockForUpdate()->get() as $line) {
                $supplierItem = ItemSupplier::query()->where('item_id', $line->item_id)
                    ->where('supplier_account_id', $order->supplier_account_id)->where('active', true)->first();
                $line->update([
                    'item_code' => $line->item->code,
                    'supplier_item_code' => $supplierItem?->supplier_item_code,
                    'over_receipt_mode' => $line->over_receipt_mode ?? $settings->over_receipt_mode,
                    'over_receipt_tolerance_percent' => $line->over_receipt_tolerance_percent ?? $settings->over_receipt_tolerance_percent,
                ]);
            }
            $order->update(['number' => $this->numbers->next('purchase_order'), 'status' => PurchaseOrderStatus::Issued, 'issued_at' => now()]);

            return $order->refresh()->load('lines');
        });
    }
}

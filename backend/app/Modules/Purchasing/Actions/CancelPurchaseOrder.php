<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Illuminate\Support\Facades\DB;

final class CancelPurchaseOrder
{
    public function __invoke(PurchaseOrder $order, string $reason): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Issued], true)) {
                throw new DomainInvariantException('This purchase order cannot be cancelled.', 'status');
            }
            $order->update(['status' => PurchaseOrderStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);

            return $order->refresh()->load('lines');
        });
    }
}

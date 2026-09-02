<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdatePurchaseOrder
{
    public function __construct(private readonly BuildPurchaseOrderLineAttributes $buildLine) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $attributes): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new DomainInvariantException('Only a draft purchase order can be edited.', 'status');
            }
            $lines = Arr::pull($attributes, 'lines');
            $order->update($attributes);
            if (is_array($lines)) {
                $order->lines()->delete();
                foreach ($lines as $line) {
                    $order->lines()->create(($this->buildLine)($order, $line));
                }
            }

            return $order->refresh()->load('lines');
        });
    }
}

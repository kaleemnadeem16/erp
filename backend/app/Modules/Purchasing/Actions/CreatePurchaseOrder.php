<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CreatePurchaseOrder
{
    public function __construct(private readonly BuildPurchaseOrderLineAttributes $buildLine) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes): PurchaseOrder
    {
        return DB::transaction(function () use ($attributes): PurchaseOrder {
            $lines = Arr::pull($attributes, 'lines', []);
            $order = PurchaseOrder::create($attributes)->refresh();
            foreach ($lines as $line) {
                $order->lines()->create(($this->buildLine)($order, $line));
            }

            return $order->load('lines');
        });
    }
}

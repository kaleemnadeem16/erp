<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\ActionAuthorization;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CreatePurchaseOrder
{
    public function __construct(private readonly BuildPurchaseOrderLineAttributes $buildLine) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): PurchaseOrder
    {
        ActionAuthorization::require($actor, 'purchasing.purchase-order.create', 'create a purchase order');

        return $this->forWorkflow($attributes);
    }

    /**
     * Creates the order for a workflow Action that has already authorised its own actor under its own capability (the open-PO
     * cutover commit, `purchasing.cutover.commit`) — that person is not asked to hold the drafting capability as well. Nothing else
     * may call this; everything with a user in front of it calls the Action itself.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function forWorkflow(array $attributes): PurchaseOrder
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

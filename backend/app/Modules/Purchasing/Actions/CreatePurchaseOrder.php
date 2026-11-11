<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\ActionAuthorization;
use App\Support\Scope\DecisionActor;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class CreatePurchaseOrder
{
    public function __construct(private readonly BuildPurchaseOrderLineAttributes $buildLine, private readonly ReconcilePurchaseOrderFx $reconcileFx) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor): PurchaseOrder
    {
        ActionAuthorization::require($actor, 'purchasing.purchase-order.create', 'create a purchase order');

        // The typed rate decides base amounts, the approval band and receipt valuation, so it is checked against the governed rate first (issue #90).
        return DB::transaction(function () use ($attributes, $actor): PurchaseOrder {
            // The early check only fails fast. The decision is taken from the stored actor, under the company access barrier, before any row lock.
            $actor = DecisionActor::require($actor, 'purchasing.purchase-order.create', 'create a purchase order');

            return $this->forWorkflow(array_merge($attributes, ($this->reconcileFx)($attributes, $actor)));
        });
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

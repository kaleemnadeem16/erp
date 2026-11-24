<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderAccess;
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

            $access = PurchaseOrderAccess::make();
            $access->requireReachable($actor, 'purchasing.purchase-order.create', $actor->id, isset($attributes['ship_to_warehouse_id']) ? (int) $attributes['ship_to_warehouse_id'] : null, isset($attributes['buyer_user_id']) ? (int) $attributes['buyer_user_id'] : null);
            if (isset($attributes['replaces_purchase_order_id'])) {
                // Naming a replaced order reveals it: only one this person may reach can be named, and anything else reads as absent.
                $access->lockForDuty($actor, (int) $attributes['replaces_purchase_order_id'], 'purchasing.purchase-order.create');
            }

            // The drafter is recorded by the server, never taken from the request; it is the identity record scope will later follow.
            return $this->forWorkflow(array_merge($attributes, ($this->reconcileFx)($attributes, $actor), ['created_by' => $actor->id]));
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

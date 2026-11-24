<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\CompanyContext;
use App\Support\Scope\RecordScope;
use App\Support\Scope\ResolvedScope;
use App\Support\Scope\ResourceScopeAdapter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Exact-duty purchase-order scope (Q26, ADR 0192). Declares which duties an administrator may limit, and turns a resolved scope into a query predicate.
 *
 *  - OWN        = the order's immutable creator (`created_by`) is the actor. It is NOT "owner" in the Sales sense and does not include the assigned buyer.
 *  - MY_UNITS   = the order's ship-to warehouse belongs to one of the actor's active organisation units (or their descendants).
 *  - ALL        = no limit; every existing grant stays this until an administrator sets a limit.
 *  - OWN_OR_DELEGATED is a Sales concept (named delegation) and is unsupported here: it fails closed.
 *
 * An order with no creator or no ship-to (history older than ADR 0191) matches neither limited path, so a limited person never reaches it
 * and an unrestricted person always does. No identity is ever inferred for it.
 */
final class PurchaseOrderScopeAdapter implements ResourceScopeAdapter
{
    public const RESOURCE = 'purchase_order';

    /** Duties an administrator may limit. Field/approval duties are deliberately absent: they never confer record reach. */
    public const LIMITABLE = [
        'purchasing.purchase-order.view',
        'purchasing.purchase-order.create',
        'purchasing.purchase-order.submit',
        'purchasing.purchase-order.issue',
        'purchasing.purchase-order.cancel',
        'purchasing.purchase-order.short-close',
        // A field permission, not a record duty: listed only so a limited buyer's role may also carry it (it never widens reach).
        'purchasing.commercial-cost.view',
    ];

    public function supports(string $duty, string $scope): bool
    {
        return in_array($duty, self::LIMITABLE, true)
            && in_array($scope, ['ALL', AccessScopeLimit::OWN, AccessScopeLimit::MY_UNITS], true);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function apply(Builder $query, ResolvedScope $scope, int $actorId): Builder
    {
        $query->where('purchase_orders.company_id', app(CompanyContext::class)->id());
        if ($scope->isAll()) {
            return $query;
        }
        $warehouses = app(RecordScope::class)->resourceIds($scope, 'warehouse') ?? [];
        if (! $scope->ownsRecords() && $warehouses === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $paths) use ($scope, $actorId, $warehouses): void {
            if ($scope->ownsRecords()) {
                $paths->where('purchase_orders.created_by', $actorId);
            }
            if ($warehouses !== []) {
                $paths->orWhereIn('purchase_orders.ship_to_warehouse_id', $warehouses);
            }
        });
    }

    /** Does the scope reach a warehouse a person may name on an order? A limited person may not file an order into a warehouse they cannot see. */
    public function reachesWarehouse(ResolvedScope $scope, ?int $warehouseId): bool
    {
        if ($scope->isAll() || $scope->ownsRecords()) {
            return true;
        }

        return $warehouseId !== null && in_array($warehouseId, app(RecordScope::class)->resourceIds($scope, 'warehouse') ?? [], true);
    }
}

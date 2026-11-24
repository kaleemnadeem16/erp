<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\Scope\RecordScope;
use App\Support\Scope\ResolvedScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Which purchase orders a person may reach (Q26, ADR 0192). Applied explicitly where orders leave or enter the system, never as a global scope:
 * internal workflows (receipt posting, cutover, approvals) read what they need whoever triggered them and are listed in the ADR.
 *
 *  - READ reach is the widest reach among the PO record duties the person holds (view, create, issue, cancel, short-close).
 *    A Goods Receipt / quality / cutover duty confers PO reads only when the person holds NO PO record duty, so an unrelated
 *    unrestricted duty can never widen a limited PO grant.
 *  - WRITE reach is judged by the exact duty being exercised, so a person limited on issue cannot issue what they cannot reach.
 *  - Out of reach is indistinguishable from absent: ModelNotFoundException, rendered as 404.
 */
final class PurchaseOrderAccess
{
    /** PO record duties that already confer reading orders. */
    public const READ_DUTIES = [
        'purchasing.purchase-order.view',
        'purchasing.purchase-order.create',
        'purchasing.purchase-order.issue',
        'purchasing.purchase-order.cancel',
        'purchasing.purchase-order.short-close',
    ];

    /** Adjacent duties that read orders to do their own work; consulted only when no PO record duty is held. */
    public const ADJACENT_READ_DUTIES = [
        'purchasing.goods-receipt.create',
        'purchasing.goods-receipt.post',
        'purchasing.goods-receipt.reverse',
        'purchasing.quality-disposition.post',
        'purchasing.cutover.commit',
    ];

    public function __construct(private readonly RecordScope $records, private readonly PurchaseOrderScopeAdapter $adapter) {}

    public static function make(): self
    {
        return app(self::class);
    }

    /** @phpstan-impure reads grants and limits from the database on every call */
    public function readScope(User $user): ResolvedScope
    {
        // Names held, not hasPermissionTo(): that throws for a permission row that does not exist yet.
        $held = array_intersect(self::READ_DUTIES, $user->getAllPermissions()->pluck('name')->all());
        if ($held === []) {
            return $this->records->resolve($user, self::ADJACENT_READ_DUTIES);
        }
        $own = false;
        $units = [];
        foreach ($held as $duty) {
            $scope = $this->records->resolveForResource($user, $duty, PurchaseOrderScopeAdapter::RESOURCE);
            if ($scope->isAll()) {
                return $scope;
            }
            $own = $own || $scope->ownsRecords();
            $units = array_merge($units, $scope->unitIds());
        }

        return ResolvedScope::records($own, $units);
    }

    /** @phpstan-impure */
    public function writeScope(User $user, string $duty): ResolvedScope
    {
        return $this->records->resolveForResource($user, $duty, PurchaseOrderScopeAdapter::RESOURCE);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeForRead(Builder $query, User $user): Builder
    {
        return $this->adapter->apply($query, $this->readScope($user), $user->id);
    }

    /**
     * @param  Builder<PurchaseOrder>  $query
     * @return Builder<PurchaseOrder>
     */
    public function scopeForDuty(Builder $query, User $user, string $duty): Builder
    {
        return $this->adapter->apply($query, $this->writeScope($user, $duty), $user->id);
    }

    /** HTTP entry for a read: the bound order must be reachable, else 404. */
    public function requireRead(User $user, PurchaseOrder $order): void
    {
        if (! $this->scopeForRead(PurchaseOrder::query(), $user)->whereKey($order->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$order->id]);
        }
    }

    /** HTTP entry for a write: the order must be reachable under the exact duty, else 404. */
    public function requireDuty(User $user, PurchaseOrder $order, string $duty): void
    {
        if (! $this->scopeForDuty(PurchaseOrder::query(), $user, $duty)->whereKey($order->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(PurchaseOrder::class, [$order->id]);
        }
    }

    /**
     * Action boundary: re-reads and locks the order inside the caller's transaction, after the company access barrier, judging reach under
     * the exact duty from the freshly loaded actor. Absent and unreachable are the same ModelNotFoundException.
     *
     * @param  bool  $workflowReach  the caller already holds an unrestricted workflow capability that is allowed to reach this order (open-PO cutover issue)
     */
    public function lockForDuty(User $actor, int $orderId, string $duty, bool $workflowReach = false): PurchaseOrder
    {
        $query = PurchaseOrder::query()->lockForUpdate();
        if (! $workflowReach) {
            $query = $this->scopeForDuty($query, $actor, $duty);
        }

        return $query->findOrFail($orderId);
    }

    /** A limited person may not file or move an order into a warehouse outside their reach: they would lose sight of it. */
    public function requireShipToReachable(User $actor, string $duty, ?int $warehouseId): void
    {
        if (! $this->adapter->reachesWarehouse($this->writeScope($actor, $duty), $warehouseId)) {
            throw new DomainInvariantException('This warehouse is outside the purchase orders you may handle.', 'ship_to_warehouse_id');
        }
    }

    /** True when the person may reach every order of the company for this duty. */
    public function isUnrestricted(User $actor, string $duty): bool
    {
        return $this->writeScope($actor, $duty)->isAll();
    }
}

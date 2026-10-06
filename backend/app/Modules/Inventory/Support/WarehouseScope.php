<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Support\Scope\RecordScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Applies a person's record scope (ADR 0089, Q26) to inventory by warehouse. The scope answer comes from RecordScope; this turns it into
 * what Inventory queries need: the warehouses a person reaches, and the places the rule has to be applied.
 *
 * It is applied explicitly where data leaves the API or a write begins, never as a global model scope: stock layers are also read by
 * costing and posting on behalf of whoever triggered them, and those reads must see everything they need.
 *
 * A person with no limit reaches every warehouse (null). A limited person reaches the warehouses owned by their units; a warehouse owned
 * by no unit is reached by nobody who is limited.
 */
final class WarehouseScope
{
    /**
     * @param  string|list<string>  $permissions  any one of them confers the access
     * @return list<int>|null null when every warehouse is reached
     */
    public static function warehouseIds(?User $user, string|array $permissions): ?array
    {
        $records = app(RecordScope::class);

        return $records->resourceIds($records->resolve($user, $permissions), 'warehouse');
    }

    /** @param  string|list<string>  $permissions */
    public static function unrestricted(?User $user, string|array $permissions): bool
    {
        return self::warehouseIds($user, $permissions) === null;
    }

    /** @param  string|list<string>  $permissions */
    public static function permitsWarehouse(?User $user, string|array $permissions, int $warehouseId): bool
    {
        $ids = self::warehouseIds($user, $permissions);

        return $ids === null || in_array($warehouseId, $ids, true);
    }

    /** @param  string|list<string>  $permissions */
    public static function permitsLocation(?User $user, string|array $permissions, ?int $locationId): bool
    {
        $ids = self::warehouseIds($user, $permissions);
        if ($ids === null) {
            return true;
        }

        return $locationId !== null && $ids !== []
            && DB::table('locations')->where('id', $locationId)->whereIn('warehouse_id', $ids)->exists();
    }

    /**
     * Limit a query to rows whose location is in a reached warehouse. Used for list, count, search, filter, sort and include alike,
     * because it is a WHERE on the query that every one of them shares.
     *
     * @param  Builder<*>  $query
     * @param  string|list<string>  $permissions
     */
    public static function limitToLocation(Builder $query, ?User $user, string|array $permissions, string $column = 'location_id'): void
    {
        $ids = self::warehouseIds($user, $permissions);
        if ($ids === null) {
            return;
        }
        $query->whereIn($column, fn (QueryBuilder $sub) => $sub->select('id')->from('locations')->whereIn('warehouse_id', $ids === [] ? [0] : $ids));
    }

    /**
     * Limit a movement query to rows touching a reached warehouse on either side (a transfer into your warehouse is your business).
     *
     * @param  Builder<*>  $query
     * @param  string|list<string>  $permissions
     */
    public static function limitToEitherLocation(Builder $query, ?User $user, string|array $permissions, string $fromColumn = 'from_location_id', string $toColumn = 'to_location_id'): void
    {
        $ids = self::warehouseIds($user, $permissions);
        if ($ids === null) {
            return;
        }
        $ids = $ids === [] ? [0] : $ids;
        $query->where(fn (Builder $q) => $q
            ->whereIn($fromColumn, fn (QueryBuilder $sub) => $sub->select('id')->from('locations')->whereIn('warehouse_id', $ids))
            ->orWhereIn($toColumn, fn (QueryBuilder $sub) => $sub->select('id')->from('locations')->whereIn('warehouse_id', $ids)));
    }

    /** @param  Builder<*>  $query @param  string|list<string>  $permissions */
    public static function limitToWarehouse(Builder $query, ?User $user, string|array $permissions, string $column = 'warehouse_id'): void
    {
        $ids = self::warehouseIds($user, $permissions);
        if ($ids !== null) {
            $query->whereIn($column, $ids === [] ? [0] : $ids);
        }
    }

    /** The guard a write makes for itself, so a console command or job is stopped as a request is. @param  string|list<string>  $permissions */
    public static function requireWarehouse(User $actor, string|array $permissions, int $warehouseId, string $action): void
    {
        if (! self::permitsWarehouse($actor, $permissions, $warehouseId)) {
            throw new DomainInvariantException("The actor is not permitted to {$action} in this warehouse.");
        }
    }
}

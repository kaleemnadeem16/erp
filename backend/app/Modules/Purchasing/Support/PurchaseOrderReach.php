<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Support;

use App\Support\Scope\RecordScope;
use App\Support\Scope\ResolvedScope;

/**
 * A resolved scope turned into something a single record can be tested against in memory (the ship-to warehouses are looked up once).
 * The same two alternatives the query predicate uses: the actor created the order, or its ship-to warehouse is one of the reached ones.
 */
final readonly class PurchaseOrderReach
{
    /** @param list<int> $warehouseIds */
    private function __construct(private bool $all, private bool $own, private array $warehouseIds, private int $actorId) {}

    public static function of(ResolvedScope $scope, int $actorId): self
    {
        if ($scope->isAll()) {
            return new self(true, false, [], $actorId);
        }

        return new self(false, $scope->ownsRecords(), app(RecordScope::class)->resourceIds($scope, 'warehouse') ?? [], $actorId);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function isNone(): bool
    {
        return ! $this->all && ! $this->own && $this->warehouseIds === [];
    }

    /** Does this reach the order described by its two identities? Unknown identities never qualify a limited reach. */
    public function includes(?int $createdBy, ?int $shipToWarehouseId): bool
    {
        if ($this->all) {
            return true;
        }

        return ($this->own && $createdBy !== null && $createdBy === $this->actorId)
            || ($shipToWarehouseId !== null && in_array($shipToWarehouseId, $this->warehouseIds, true));
    }

    /** Does every order the other reach can see also fall inside this one? Conservative: when unsure, no. */
    public function covers(self $other): bool
    {
        if ($this->all) {
            return true;
        }
        if ($other->all) {
            return false;
        }

        return (! $other->own || $this->own) && array_diff($other->warehouseIds, $this->warehouseIds) === [];
    }
}

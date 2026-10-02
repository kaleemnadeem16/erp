<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/**
 * A requested narrowing of the stock an availability read may consider —
 * ADR 0058's `inventory_scope`. It can only ever narrow the actor's permitted
 * scope, never widen it; today that permitted scope is the whole company
 * (ADR 0057: Q26's organisational scopes do not exist yet), so an empty scope
 * means "every warehouse and location of this company". When both lists are
 * given the result is their intersection.
 */
final class InventoryScope
{
    /** @var list<int> */
    public readonly array $warehouseIds;

    /** @var list<int> */
    public readonly array $locationIds;

    /**
     * @param  list<int>  $warehouseIds
     * @param  list<int>  $locationIds
     */
    public function __construct(array $warehouseIds = [], array $locationIds = [])
    {
        $this->warehouseIds = $warehouseIds;
        $this->locationIds = $locationIds;
    }

    public function narrowed(): bool
    {
        return $this->warehouseIds !== [] || $this->locationIds !== [];
    }
}

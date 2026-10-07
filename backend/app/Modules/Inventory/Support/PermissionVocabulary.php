<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

/** Inventory-owned capabilities registered through the shared permission registry. */
final class PermissionVocabulary
{
    /** @return list<string> */
    public static function all(): array
    {
        return [
            'inventory.item.manage',
            'inventory.category.manage',
            'inventory.location.manage',
            'inventory.warehouse.manage',
            'inventory.physical_count.create',
            'inventory.physical_count.count',
            'inventory.physical_count.post',
            'inventory.cutover.create',
            'inventory.cutover.stage',
            'inventory.cutover.validate',
            'inventory.cutover.approve',
            'inventory.cutover.commit',
            'inventory.cutover.finalise',
            'inventory.cutover.negative_opening.request',
            'inventory.cutover.negative_opening.approve',
            'inventory.negative-stock.request',
            'inventory.negative-stock.override',
            'inventory.negative-stock.approve',
            'inventory.negative-stock.review',
            'inventory.recalculation.request',
            'inventory.recalculation.approve',
            'inventory.recalculation.approve-high-risk',
            'inventory.recalculation.execute',
            'inventory.recalculation.review-regeneration',
            'inventory.location-policy.manage',
            'inventory.valuation-policy.manage',
            // Stock reads (ADR 0083): what is in stock, where, and its history — separate from value (below) and from each other.
            'inventory.availability.view',
            'inventory.stock.view',
            'inventory.movement.view',
            'inventory.valuation.view',
            'inventory.policy-history.view',
            'inventory.package-state.view',
            'inventory.package-state.open',
            'inventory.package-state.issue',
            'inventory.negative-stock-policy.manage',
            'inventory.backdating-horizon-policy.manage',
            'inventory.recalculation-policy.manage',
        ];
    }
}

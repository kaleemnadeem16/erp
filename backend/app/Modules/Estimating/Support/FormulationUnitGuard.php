<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;

/**
 * Keeps formulation quantities convertible to the selected item's stock unit.
 * The UI defaults those units for convenience, but this boundary protects API
 * and import callers too.
 */
final class FormulationUnitGuard
{
    /** @param list<array<string, mixed>> $lines */
    public function __invoke(int $outputItemId, int $outputUomId, array $lines): void
    {
        $this->assertSameDimension($outputItemId, $outputUomId, 'output_uom_id', 'Output');

        foreach ($lines as $index => $line) {
            $this->assertSameDimension(
                (int) $line['input_item_id'],
                (int) $line['uom_id'],
                "lines.{$index}.uom_id",
                'Ingredient',
            );
        }
    }

    private function assertSameDimension(int $itemId, int $uomId, string $field, string $label): void
    {
        $item = Item::query()->findOrFail($itemId);
        $stockUom = Uom::query()->findOrFail($item->stock_uom_id);
        $selectedUom = Uom::query()->findOrFail($uomId);

        if ($stockUom->dimension !== $selectedUom->dimension) {
            throw new DomainInvariantException(
                "{$label} UoM must have the same dimension as the item's stock UoM.",
                $field,
            );
        }
    }
}

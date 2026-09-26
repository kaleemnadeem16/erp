<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use App\Exceptions\DomainInvariantException;
use App\Modules\Estimating\Models\Formulation;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\ItemType;

/**
 * A formulation must not, directly or transitively, consume its own output
 * — see docs/domain/formulations.md#nesting. Validated on save, not at
 * roll-up time, by walking each proposed line's input through any nested
 * ACTIVE sub-formulation. Depth-capped the same way
 * ExplodeFormulationVersion's roll-up is, as a backstop for whatever this
 * check itself might miss.
 */
final class FormulationCycleGuard
{
    private const MAX_DEPTH = 10;

    /** @param list<int> $proposedInputItemIds */
    public function __invoke(int $outputItemId, array $proposedInputItemIds): void
    {
        foreach ($proposedInputItemIds as $inputItemId) {
            $this->walk($outputItemId, $inputItemId, 1, [$inputItemId]);
        }
    }

    /** @param list<int> $path */
    private function walk(int $outputItemId, int $currentItemId, int $depth, array $path): void
    {
        if ($currentItemId === $outputItemId) {
            throw new DomainInvariantException(
                'This formulation would consume its own output, directly or through a nested '.
                'formulation: item '.implode(' -> ', array_map(strval(...), $path)),
                field: 'input_item_id',
            );
        }

        if ($depth > self::MAX_DEPTH) {
            throw new DomainInvariantException(
                'Formulation nesting exceeds the maximum supported depth while checking for cycles.',
                field: 'input_item_id',
            );
        }

        $item = Item::query()->find($currentItemId);

        if ($item === null || $item->type !== ItemType::SemiFinished) {
            return;
        }

        $subFormulation = Formulation::query()->where('output_item_id', $currentItemId)->first();

        if ($subFormulation === null) {
            return;
        }

        $activeVersion = $subFormulation->activeVersion()->with('lines')->first();

        if ($activeVersion === null) {
            return;
        }

        foreach ($activeVersion->lines as $line) {
            $this->walk($outputItemId, $line->input_item_id, $depth + 1, [...$path, $line->input_item_id]);
        }
    }
}

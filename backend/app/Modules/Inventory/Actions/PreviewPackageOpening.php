<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Support\PackageOpeningComposition;
use Illuminate\Support\Facades\DB;

/** Read-only physical transformation proposal; never posts or reserves packages. */
final class PreviewPackageOpening
{
    /** @return array<string,mixed> */
    public function __invoke(int $operationId, int $stateId, int $count, string $kind, User $actor): array
    {
        return DB::transaction(function () use ($operationId, $stateId, $count, $kind, $actor): array {
            // Replay enforces company/view authority and holds the stock location lock.
            $snapshot = app(ReplayPackageCheckpoint::class)($operationId, $actor);
            if (! $snapshot['matches_current_stock']) {
                throw new DomainInvariantException('A fresh full-scope counted checkpoint is required: '.implode(', ', $snapshot['mismatch_reasons']));
            }
            /** @var list<array<string,mixed>> $composition */
            $composition = $snapshot['composition'];
            $selected = collect($composition)->firstWhere('state_id', $stateId);
            if ($selected === null) {
                throw new DomainInvariantException('Selected package state does not belong to this checkpoint.');
            }
            if ($selected['form'] !== 'SEALED') {
                throw new DomainInvariantException('Opening or unpacking requires a sealed package selection.');
            }
            $inner = null;
            $innerCount = null;
            if ($kind === 'UNPACK') {
                $outer = ItemPackaging::query()->findOrFail($selected['packaging_id']);
                $definition = $outer->inner_packaging_id === null ? null : ItemPackaging::query()->find($outer->inner_packaging_id);
                if ($definition === null || (int) $definition->item_id !== (int) $outer->item_id) {
                    throw new DomainInvariantException('Unpacking requires a same-item inner package definition.');
                }
                $innerCount = $outer->inner_count;
                $inner = ['packaging_id' => $definition->id, 'packaging_code' => $definition->code,
                    'packaging_name' => $definition->name, 'content_quantity' => $definition->stock_quantity];
            }

            return ['checkpoint_id' => $operationId, 'stock_move_watermark' => $snapshot['stock_move_watermark'],
                'kind' => $kind, 'tracking_active' => false, 'posted' => false, 'reserved' => false,
                'quantity' => $snapshot['recorded_quantity'],
                'composition' => app(PackageOpeningComposition::class)($composition, $stateId, $count, $kind, $inner, $innerCount)];
        });
    }
}

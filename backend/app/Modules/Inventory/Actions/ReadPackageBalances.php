<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\PackageOperation;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Inventory\Support\WarehouseScope;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;

/**
 * The physical breakdown of an item's stock per location and batch, for screens: total litres together with sealed packages and loose quantity,
 * whether the evidence is fresh, and why not. Read-only. A scope with stock but no package evidence is reported honestly as such, never inferred.
 */
final class ReadPackageBalances
{
    private const CHAIN = PackageOperation::CHAIN;

    /**
     * @return list<array<string,mixed>>
     */
    public function __invoke(int $itemId, ?int $locationId, ?int $batchId, User $actor): array
    {
        if ((int) $actor->company_id !== app(CompanyContext::class)->id() || ! $actor->can('inventory.package-state.view')) {
            throw new DomainInvariantException('Package-state view authority in the current company is required.');
        }
        $item = Item::query()->findOrFail($itemId);
        $scopes = [];
        $layers = StockLayer::query()->where('item_id', $item->id)->where('qty_remaining', '>', 0)->whereNull('serial_no')
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))->get();
        foreach ($layers as $layer) {
            $scopes[$layer->location_id.'|'.($layer->batch_id ?? 'none')] = [$layer->location_id, $layer->batch_id];
        }
        foreach (PackageOperation::query()->where('item_id', $item->id)->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->whereIn('evidence_kind', self::CHAIN)->get(['location_id', 'batch_id']) as $row) {
            $scopes[$row->location_id.'|'.($row->batch_id ?? 'none')] = [$row->location_id, $row->batch_id];
        }
        $result = [];
        foreach ($scopes as [$location, $batch]) {
            if ($batchId !== null && $batch !== $batchId) {
                continue;
            }
            if (! WarehouseScope::permitsLocation($actor, 'inventory.package-state.view', $location)) {
                continue;
            }
            $result[] = $this->scope($item, $location, $batch, $actor);
        }
        usort($result, fn (array $a, array $b): int => [$a['location']['id'], $a['batch_id'] ?? 0] <=> [$b['location']['id'], $b['batch_id'] ?? 0]);

        return $result;
    }

    /** @return array<string,mixed> */
    private function scope(Item $item, int $locationId, ?int $batchId, User $actor): array
    {
        $location = Location::query()->with('warehouse')->findOrFail($locationId);
        $ledger = (string) StockLayer::query()->where(['item_id' => $item->id, 'location_id' => $locationId, 'batch_id' => $batchId, 'serial_no' => null])->sum('qty_remaining');
        $head = PackageOperation::query()->where(['item_id' => $item->id, 'location_id' => $locationId, 'batch_id' => $batchId])
            ->whereIn('evidence_kind', self::CHAIN)->orderByDesc('id')->first();
        $base = [
            'item_id' => $item->id, 'batch_id' => $batchId,
            'location' => ['id' => $location->id, 'code' => $location->code, 'name' => $location->name, 'warehouse_name' => $location->warehouse->name],
            'package_tracking_mode' => $item->package_tracking_mode->value,
        ];
        if ($head === null) {
            return $base + ['total' => (string) BigDecimal::of($ledger)->toScale(4), 'usable_total' => '0.0000', 'sealed' => [], 'loose' => '0.0000', 'held' => ['sealed' => [], 'loose' => '0.0000', 'total' => '0.0000'], 'rejected' => ['sealed' => [], 'loose' => '0.0000', 'total' => '0.0000'], 'fresh' => false, 'reasons' => ['NO_EVIDENCE'], 'operation_id' => null];
        }
        $snapshot = app(ReplayPackageCheckpoint::class)($head->id, $actor);
        // Usable stock is the top-level breakdown (what can be issued, transferred, opened); held and rejected packages (QA, ADR 0094) are reported beside it.
        $groups = ['USABLE' => ['sealed' => [], 'loose' => BigDecimal::zero()], 'HELD' => ['sealed' => [], 'loose' => BigDecimal::zero()], 'REJECTED' => ['sealed' => [], 'loose' => BigDecimal::zero()]];
        foreach ($snapshot['composition'] as $row) {
            $group = $row['availability_evidence'];
            if ($row['form'] === 'SEALED') {
                $groups[$group]['sealed'][] = ['item_packaging_id' => $row['packaging_id'], 'code' => $row['packaging_code'], 'name' => $row['packaging_name'],
                    'count' => (int) $row['sealed_count'], 'content_quantity' => $row['content_quantity'], 'quantity' => $row['quantity'], 'availability' => $group];
            } else {
                $groups[$group]['loose'] = $groups[$group]['loose']->plus($row['quantity']);
            }
        }
        $sum = fn (array $g): BigDecimal => array_reduce($g['sealed'], fn (BigDecimal $c, array $r) => $c->plus($r['quantity']), $g['loose']);
        $shape = fn (array $g): array => ['sealed' => $g['sealed'], 'loose' => (string) $g['loose']->toScale(4), 'total' => (string) $sum($g)->toScale(4)];

        return $base + [
            'total' => $snapshot['current_quantity'], 'usable_total' => (string) $sum($groups['USABLE'])->toScale(4), 'sealed' => $groups['USABLE']['sealed'], 'loose' => (string) $groups['USABLE']['loose']->toScale(4),
            'held' => $shape($groups['HELD']), 'rejected' => $shape($groups['REJECTED']),
            'fresh' => $snapshot['matches_current_stock'], 'reasons' => $snapshot['mismatch_reasons'], 'operation_id' => $head->id,
        ];
    }
}

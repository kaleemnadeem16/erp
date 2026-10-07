<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Support\ImportBatchStatus;
use App\Modules\Imports\Support\ImportPurpose;
use App\Modules\Imports\Support\ImportRowStatus;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\InventoryCutoverBatch;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Support\InventoryCutoverMode;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Modules\Inventory\Support\WarehouseScope;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class AttachInventoryCutoverBatch
{
    public function __invoke(InventoryCutover $cutover, ImportBatch $importBatch, User $actor): InventoryCutoverBatch
    {
        if (! $actor->can('inventory.cutover.stage')) {
            throw new DomainInvariantException('The actor is not permitted to stage inventory cutover data.', 'created_by');
        }
        WarehouseScope::requireUnrestricted($actor, 'inventory.cutover.stage', 'stage inventory cutover data');

        return DB::transaction(function () use ($cutover, $importBatch): InventoryCutoverBatch {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            $importBatch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
            $existing = InventoryCutoverBatch::query()->where('import_batch_id', $importBatch->id)->first();
            if ($existing !== null) {
                if ($existing->inventory_cutover_id !== $cutover->id) {
                    throw new DomainInvariantException('This import batch already belongs to another inventory cutover.', 'import_batch_id');
                }

                return $existing;
            }
            if ($cutover->status !== InventoryCutoverStatus::Draft) {
                throw new DomainInvariantException('Batches can only be attached to a draft cutover.', 'status');
            }
            if ($importBatch->purpose !== ImportPurpose::InventoryCutover || $importBatch->status !== ImportBatchStatus::Validated) {
                throw new DomainInvariantException('The source must be a validated INVENTORY_CUTOVER batch.', 'import_batch_id');
            }
            $rows = $importBatch->rows()->orderBy('row_number')->get();
            if ($rows->isEmpty() || $rows->contains(fn ($row): bool => $row->status !== ImportRowStatus::Valid)) {
                throw new DomainInvariantException('Every staged row must be valid before attaching the batch.', 'import_batch_id');
            }
            if (! in_array($importBatch->entity_type, ['OPENING_STATE', 'OPEN_PURCHASE_ORDER'], true)) {
                throw new DomainInvariantException('This cutover entity is not supported by an owning domain Action.', 'entity_type');
            }
            if ($importBatch->entity_type === 'OPEN_PURCHASE_ORDER' && $cutover->mode === InventoryCutoverMode::OpeningState) {
                throw new DomainInvariantException('Open purchase orders require an OPERATIONAL or FULL_HISTORY cutover.', 'mode');
            }

            $quantity = BigDecimal::zero();
            $value = BigDecimal::zero();
            $baseValue = BigDecimal::zero();
            if ($importBatch->entity_type === 'OPENING_STATE') {
                foreach ($rows as $row) {
                    $quantity = $quantity->plus((string) $row->mapped_data['quantity']);
                    $value = $value->plus((string) $row->mapped_data['amount']);
                    $baseValue = $baseValue->plus((string) $row->mapped_data['base_amount']);
                }
            }
            $link = InventoryCutoverBatch::query()->create([
                'inventory_cutover_id' => $cutover->id, 'import_batch_id' => $importBatch->id,
                'entity_type' => $importBatch->entity_type, 'source_record_count' => $rows->count(),
                'accepted_record_count' => $rows->count(), 'rejected_record_count' => 0,
                'source_quantity' => (string) $quantity, 'source_value' => (string) $value,
                'base_source_value' => (string) $baseValue,
            ]);

            if ($importBatch->entity_type === 'OPENING_STATE') {
                foreach ($rows as $row) {
                    $data = $row->mapped_data;
                    $item = Item::query()->where('code', strtolower((string) $data['item_code']))->firstOrFail();
                    $warehouse = Warehouse::query()->where('code', strtolower((string) $data['warehouse_code']))->firstOrFail();
                    $location = Location::query()->where('warehouse_id', $warehouse->id)->where('code', strtolower((string) $data['location_code']))->firstOrFail();
                    $uom = Uom::query()->where('code', $data['uom_code'])->firstOrFail();
                    $currency = ($data['currency_code'] ?? null) === null ? null : Currency::query()->where('code', strtoupper((string) $data['currency_code']))->firstOrFail();
                    $link->sourceFacts()->create([
                        'inventory_cutover_id' => $cutover->id, 'import_batch_row_id' => $row->id,
                        'source_system' => $cutover->source_system, 'source_entity_type' => $importBatch->entity_type,
                        'source_record_id' => $data['source_record_id'], 'source_effective_at' => $data['source_effective_at'] ?? null,
                        'item_id' => $item->id, 'location_id' => $location->id,
                        'source_valuation_method' => $data['source_valuation_method'], 'source_avco_scope' => $data['source_avco_scope'] ?? null,
                        'source_valuation_pool_identity' => $data['source_pool_identity'] ?? null,
                        'qty' => $data['quantity'], 'uom_id' => $uom->id, 'unit_cost' => $data['unit_cost'] ?? null,
                        'amount' => $data['amount'], 'base_amount' => $data['base_amount'],
                        'currency_id' => $currency?->id, 'fx_rate' => $data['fx_rate'] ?? null,
                        'batch_no' => $data['batch_no'] ?? null, 'serial_no' => $data['serial_no'] ?? null,
                        'source_payload' => ['raw' => $row->raw_data, 'mapped' => $data],
                    ]);
                }
            }

            return $link->load('sourceFacts');
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Currency;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Imports\Models\ImportBatchRow;
use App\Modules\Inventory\Models\InventoryCutover;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Support\InventoryCutoverBatchStatus;
use App\Modules\Inventory\Support\InventoryCutoverStatus;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Purchasing\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchasing-owned V1 materialisation of still-open predecessor PO lines.
 * It creates a new issued target PO for the remaining commitment only; it
 * never recreates historical receipts or invents follow-on document state.
 */
final class CommitInventoryCutoverOpenPurchaseOrders
{
    private const HEADER_FIELDS = [
        'supplier_code', 'supplier_site_code', 'currency_code', 'fx_policy', 'fx_rate',
        'fx_rate_date', 'fx_rate_type', 'fx_rate_source', 'order_date', 'expected_delivery_date',
    ];

    public function __construct(
        private readonly CreatePurchaseOrder $create,
        private readonly IssuePurchaseOrder $issue,
    ) {}

    /** @return Collection<int, PurchaseOrder> */
    public function __invoke(InventoryCutover $cutover, User $actor): Collection
    {
        if (! $actor->can('purchasing.cutover.commit')) {
            throw new DomainInvariantException('The actor is not permitted to commit cutover purchase orders.', 'status');
        }

        return DB::transaction(function () use ($cutover): Collection {
            $cutover = InventoryCutover::query()->whereKey($cutover->id)->lockForUpdate()->firstOrFail();
            if ($cutover->status !== InventoryCutoverStatus::Approved) {
                throw new DomainInvariantException('Only an approved cutover can materialise open purchase orders.', 'status');
            }
            $batches = $cutover->batches()->where('entity_type', 'OPEN_PURCHASE_ORDER')->lockForUpdate()->get();
            if ($batches->isEmpty()) {
                throw new DomainInvariantException('No open-purchase-order batch is attached to this cutover.', 'batches');
            }
            if ($batches->contains(fn ($batch): bool => ! in_array($batch->status, [InventoryCutoverBatchStatus::Validated, InventoryCutoverBatchStatus::Committed], true))) {
                throw new DomainInvariantException('Every open-purchase-order batch must be validated before commit.', 'batches');
            }
            $pending = $batches->where('status', InventoryCutoverBatchStatus::Validated);
            if ($pending->isEmpty()) {
                return PurchaseOrder::query()->where('inventory_cutover_id', $cutover->id)->orderBy('id')->get();
            }

            $rows = ImportBatchRow::query()->whereIn('import_batch_id', $pending->pluck('import_batch_id'))
                ->orderBy('id')->get()->groupBy(fn (ImportBatchRow $row): string => (string) $row->mapped_data['source_purchase_order_id']);

            foreach ($rows as $sourceOrderId => $orderRows) {
                $first = $orderRows->firstOrFail()->mapped_data;
                foreach ($orderRows as $row) {
                    foreach (self::HEADER_FIELDS as $field) {
                        if (($row->mapped_data[$field] ?? null) !== ($first[$field] ?? null)) {
                            throw new DomainInvariantException("Source purchase order {$sourceOrderId} has inconsistent header facts.", $field);
                        }
                    }
                }
                $lineNumbers = $orderRows->map(fn (ImportBatchRow $row): int => (int) $row->mapped_data['line_number']);
                $sourceLineIds = $orderRows->map(fn (ImportBatchRow $row): string => (string) $row->mapped_data['source_line_id']);
                if ($lineNumbers->unique()->count() !== $lineNumbers->count() || $sourceLineIds->unique()->count() !== $sourceLineIds->count()) {
                    throw new DomainInvariantException("Source purchase order {$sourceOrderId} contains duplicate line identity.", 'line_number');
                }
                $sourceBatchIds = $orderRows->pluck('import_batch_id')->unique();
                if ($sourceBatchIds->count() !== 1) {
                    throw new DomainInvariantException("Source purchase order {$sourceOrderId} must be staged in one immutable import batch.", 'source_purchase_order_id');
                }

                $supplier = SupplierAccount::query()->whereRaw('lower(code) = ?', [strtolower((string) $first['supplier_code'])])->firstOrFail();
                $supplierSite = ($first['supplier_site_code'] ?? null) === null ? null : $supplier->sites()
                    ->whereHas('partySite', fn ($query) => $query->whereRaw('lower(code) = ?', [strtolower((string) $first['supplier_site_code'])]))
                    ->firstOrFail();
                $currency = Currency::query()->where('code', strtoupper((string) $first['currency_code']))->firstOrFail();
                $importBatchId = (int) $sourceBatchIds->sole();

                $order = ($this->create)([
                    'supplier_account_id' => $supplier->id,
                    'supplier_site_id' => $supplierSite?->id,
                    'currency_id' => $currency->id,
                    'fx_policy' => $first['fx_policy'],
                    'fx_rate' => $first['fx_rate'],
                    'fx_rate_date' => $first['fx_rate_date'],
                    'fx_rate_type' => $first['fx_rate_type'],
                    'fx_rate_source' => $first['fx_rate_source'],
                    'order_date' => $first['order_date'],
                    'expected_delivery_date' => $first['expected_delivery_date'] ?? null,
                    'inventory_cutover_id' => $cutover->id,
                    'import_batch_id' => $importBatchId,
                    'source_system' => $cutover->source_system,
                    'source_record_id' => $sourceOrderId,
                    'lines' => $orderRows->map(function (ImportBatchRow $row): array {
                        $data = $row->mapped_data;
                        $item = Item::query()->where('code', strtolower((string) $data['item_code']))->firstOrFail();
                        $uom = Uom::query()->where('code', strtolower((string) $data['purchase_uom_code']))->firstOrFail();

                        return [
                            'line_number' => (int) $data['line_number'], 'item_id' => $item->id,
                            'description' => $data['description'], 'ordered_qty' => $data['remaining_quantity'],
                            'purchase_uom_id' => $uom->id, 'unit_price' => $data['unit_price'],
                            'discount_amount' => $data['discount_amount'], 'tax_amount' => $data['tax_amount'],
                            'expected_delivery_date' => $data['line_expected_delivery_date'] ?? null,
                            'qa_required' => $data['qa_required'], 'over_receipt_mode' => $data['over_receipt_mode'],
                            'over_receipt_tolerance_percent' => $data['over_receipt_tolerance_percent'],
                            'import_batch_row_id' => $row->id, 'source_record_id' => $data['source_line_id'],
                        ];
                    })->all(),
                ]);
                ($this->issue)($order);
            }

            foreach ($pending as $batch) {
                $batch->update(['status' => InventoryCutoverBatchStatus::Committed]);
            }

            return PurchaseOrder::query()->where('inventory_cutover_id', $cutover->id)->with('lines')->orderBy('id')->get();
        });
    }
}

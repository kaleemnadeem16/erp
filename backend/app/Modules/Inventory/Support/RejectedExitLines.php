<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support;

use App\Exceptions\DomainInvariantException;
use App\Models\User;
use App\Modules\Inventory\Actions\PackageDrawPlanner;
use App\Modules\Inventory\Models\Location;
use App\Modules\Inventory\Models\StockLayer;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Brick\Math\BigDecimal;

/**
 * Validates and normalises the lines of a Supplier Return or Scrap draft (ADR 0097): each line names a delivery line whose stock exists, a positive quantity, an
 * optional outcome-change reason and, for a grouped item, a declared package portion. All lines of one document belong to one warehouse, a delivery line appears once,
 * and the person must be allowed to act in that warehouse. It reads and normalises only — nothing is posted, reserved or written.
 */
final class RejectedExitLines
{
    /**
     * @param  list<array<string,mixed>>  $lines
     * @param  list<string>  $capabilities  any one of them confers the right to act in a warehouse
     * @return array{warehouse_id:int, supplier_account_id:int|null, rows:list<array<string,mixed>>}
     */
    public static function resolve(array $lines, User $actor, array $capabilities): array
    {
        if ($lines === []) {
            throw new DomainInvariantException('A document needs at least one line.', 'lines', 'EMPTY_DOCUMENT');
        }
        $rows = [];
        $seen = [];
        $warehouse = null;
        $supplier = null;
        foreach ($lines as $index => $line) {
            $receiptLine = GoodsReceiptLine::query()->with('goodsReceipt')->find((int) ($line['goods_receipt_line_id'] ?? 0));
            if ($receiptLine === null) {
                throw new DomainInvariantException('That delivery line does not exist.', "lines.{$index}.goods_receipt_line_id");
            }
            if (isset($seen[$receiptLine->id])) {
                throw new DomainInvariantException('A delivery line may appear once in a document.', "lines.{$index}.goods_receipt_line_id", 'DUPLICATE_RECEIPT_LINE');
            }
            $seen[$receiptLine->id] = true;
            $layer = StockLayer::query()->where('source_type', 'GOODS_RECEIPT_LINE')->where('source_id', $receiptLine->id)->first();
            if ($layer === null) {
                throw new DomainInvariantException('This delivery line has no stock: it was never posted or has been reversed.', "lines.{$index}.goods_receipt_line_id", 'NOTHING_REJECTED_TO_EXIT');
            }
            if (! WarehouseScope::permitsLocation($actor, $capabilities, $layer->location_id)) {
                throw new DomainInvariantException('That delivery is outside your warehouses.', "lines.{$index}.goods_receipt_line_id", 'OUT_OF_SCOPE');
            }
            $location = Location::query()->findOrFail($layer->location_id);
            if ($warehouse !== null && $warehouse !== $location->warehouse_id) {
                throw new DomainInvariantException('All lines of one document belong to one warehouse.', "lines.{$index}.goods_receipt_line_id", 'MIXED_WAREHOUSES');
            }
            $warehouse = $location->warehouse_id;
            $deliveredBy = (int) $receiptLine->goodsReceipt->supplier_account_id;
            if ($supplier !== null && $supplier !== $deliveredBy) {
                $supplier = -1;
            } elseif ($supplier === null) {
                $supplier = $deliveredBy;
            }
            $portion = array_values(array_filter($line['package_portion'] ?? [], 'is_array'));
            $rows[] = [
                'line_no' => $index + 1, 'goods_receipt_line_id' => $receiptLine->id, 'item_id' => $receiptLine->item_id, 'location_id' => $location->id,
                'quantity' => self::quantity((string) ($line['quantity'] ?? ''), "lines.{$index}.quantity"),
                'outcome_change_reason' => self::nullable($line['outcome_change_reason'] ?? null),
                'package_portion' => $portion === [] ? null : array_map(fn (array $row): array => array_filter($row, fn ($value): bool => $value !== null), app(PackageDrawPlanner::class)->parse($portion)),
            ];
        }

        return ['warehouse_id' => (int) $warehouse, 'supplier_account_id' => $supplier === -1 ? null : $supplier, 'rows' => $rows];
    }

    public static function quantity(string $quantity, string $field): string
    {
        try {
            $value = BigDecimal::of($quantity);
            if ($value->isGreaterThan(0) && $value->getScale() <= 4) {
                return (string) $value->toScale(4);
            }
        } catch (\Throwable) {
            // domain error below
        }
        throw new DomainInvariantException('The quantity must be positive and no finer than the stock ledger.', $field);
    }

    public static function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}

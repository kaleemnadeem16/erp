<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\PurchaseOrderFxPolicy;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateGoodsReceipt
{
    public function __construct(private readonly UomConversionService $conversion) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, ?string $idempotencyKey = null): GoodsReceipt
    {
        return DB::transaction(function () use ($attributes, $idempotencyKey): GoodsReceipt {
            if ($idempotencyKey !== null) {
                $existing = GoodsReceipt::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $existing->load('lines');
                }
            }

            $lines = Arr::pull($attributes, 'lines');
            $receipt = GoodsReceipt::query()->create(array_merge($attributes, [
                'recorded_at' => now(), 'idempotency_key' => $idempotencyKey,
            ]));

            foreach ($lines as $input) {
                $poLine = PurchaseOrderLine::query()->with('purchaseOrder')->findOrFail($input['purchase_order_line_id']);
                $item = Item::query()->findOrFail($poLine->item_id);
                if ($poLine->purchaseOrder->status !== PurchaseOrderStatus::Issued
                    || $poLine->purchaseOrder->supplier_account_id !== $receipt->supplier_account_id) {
                    throw new DomainInvariantException('Receipt lines require an issued PO line for the same supplier.', 'purchase_order_line_id');
                }
                if ($input['item_id'] !== $poLine->item_id || $input['currency_id'] !== $poLine->purchaseOrder->currency_id) {
                    throw new DomainInvariantException('Receipt item and currency must match the issued PO line.', 'purchase_order_line_id');
                }
                if ($poLine->purchaseOrder->fx_policy === PurchaseOrderFxPolicy::Fixed
                    && BigDecimal::of((string) $input['fx_rate'])->compareTo(BigDecimal::of($poLine->purchaseOrder->fx_rate)) !== 0) {
                    throw new DomainInvariantException('A FIXED purchase order requires its pinned FX rate.', 'fx_rate');
                }

                $receivedUom = Uom::query()->findOrFail($input['received_uom_id']);
                $stockUom = Uom::query()->findOrFail($item->stock_uom_id);
                try {
                    $factor = $this->conversion->convert('1', $receivedUom, $stockUom);
                } catch (InvalidArgumentException) {
                    throw new DomainInvariantException('Received UoM must have the same dimension as the item stock UoM.', 'received_uom_id');
                }
                $stockQty = BigDecimal::of((string) $input['received_qty'])->multipliedBy($factor)->toScale(4, RoundingMode::HALF_UP);
                $baseUnitCost = BigDecimal::of((string) $input['provisional_unit_cost'])
                    ->multipliedBy(BigDecimal::of((string) $input['fx_rate']))->toScale(4, RoundingMode::HALF_UP);

                $receipt->lines()->create(array_merge($input, [
                    'stock_qty' => (string) $stockQty, 'stock_uom_id' => $stockUom->id,
                    'uom_factor' => (string) $factor, 'base_unit_cost' => (string) $baseUnitCost,
                ]));
            }

            return $receipt->refresh()->load('lines');
        });
    }
}

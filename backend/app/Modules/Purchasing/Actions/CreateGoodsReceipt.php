<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Models\User;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Purchasing\Support\PurchaseOrderFxPolicy;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use App\Modules\Purchasing\Support\PurchasingCommercialAccess;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateGoodsReceipt
{
    private const COMMERCIAL_FIELDS = [
        'provisional_unit_cost', 'currency_id', 'fx_rate', 'fx_rate_date', 'fx_rate_type', 'fx_rate_source',
    ];

    public function __construct(private readonly ItemUomConversionService $conversion) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(array $attributes, User $actor, ?string $idempotencyKey = null): GoodsReceipt
    {
        if (! $actor->can('purchasing.goods-receipt.create')) {
            throw new DomainInvariantException('The actor is not permitted to create a goods receipt.');
        }
        if (! PurchasingCommercialAccess::canView($actor)) {
            foreach ($attributes['lines'] ?? [] as $line) {
                foreach (self::COMMERCIAL_FIELDS as $field) {
                    if (array_key_exists($field, $line)) {
                        throw new DomainInvariantException('The actor is not permitted to override receipt commercial values.', $field);
                    }
                }
            }
        }

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
                $hasExplicitCost = array_key_exists('provisional_unit_cost', $input);
                $poLine = PurchaseOrderLine::query()->with('purchaseOrder')->findOrFail($input['purchase_order_line_id']);
                $item = Item::query()->findOrFail($poLine->item_id);
                if ($poLine->purchaseOrder->status !== PurchaseOrderStatus::Issued
                    || $poLine->purchaseOrder->supplier_account_id !== $receipt->supplier_account_id) {
                    throw new DomainInvariantException('Receipt lines require an issued PO line for the same supplier.', 'purchase_order_line_id');
                }
                $input = array_merge([
                    'provisional_unit_cost' => $poLine->unit_price,
                    'currency_id' => $poLine->purchaseOrder->currency_id,
                    'fx_rate' => $poLine->purchaseOrder->fx_rate,
                    'fx_rate_date' => $poLine->purchaseOrder->fx_rate_date->format('Y-m-d'),
                    'fx_rate_type' => $poLine->purchaseOrder->fx_rate_type,
                    'fx_rate_source' => $poLine->purchaseOrder->fx_rate_source,
                ], $input);

                if ($input['item_id'] !== $poLine->item_id || $input['currency_id'] !== $poLine->purchaseOrder->currency_id) {
                    throw new DomainInvariantException('Receipt item and currency must match the issued PO line.', 'purchase_order_line_id');
                }
                if ($poLine->purchaseOrder->fx_policy === PurchaseOrderFxPolicy::Fixed
                    && BigDecimal::of((string) $input['fx_rate'])->compareTo(BigDecimal::of($poLine->purchaseOrder->fx_rate)) !== 0) {
                    throw new DomainInvariantException('A FIXED purchase order requires its pinned FX rate.', 'fx_rate');
                }

                $packageId = $input['item_packaging_id'] ?? null;
                $packaged = $packageId !== null;
                if ($packaged && $packageId !== $poLine->item_packaging_id) {
                    throw new DomainInvariantException('Received package must match the issued agreement; record an explicit replacement order for changed packaging.', 'item_packaging_id');
                }
                $receivedUom = Uom::query()->findOrFail($input['received_uom_id']);
                $stockUom = Uom::query()->findOrFail($item->stock_uom_id);
                try {
                    $factor = ($packaged || ($poLine->item_packaging_id === null && $receivedUom->id === $poLine->purchase_uom_id))
                        ? BigDecimal::of($poLine->uom_factor)
                        : $this->conversion->stockUnitFactor($item, $receivedUom);
                } catch (InvalidArgumentException) {
                    throw new DomainInvariantException('Received UoM must have the same dimension as the item stock UoM.', 'received_uom_id');
                }
                if ($packaged && $receivedUom->id !== $poLine->purchase_uom_id) {
                    throw new DomainInvariantException('Packaged receipt must use the issued unit reference.', 'received_uom_id');
                }
                if ($packaged && $poLine->package_increment !== null
                    && ! BigDecimal::of((string) $input['received_qty'])->remainder($poLine->package_increment)->isZero()) {
                    throw new DomainInvariantException('Received quantity violates the agreed package increment.', 'received_qty');
                }
                $stockQty = BigDecimal::of((string) $input['received_qty'])->multipliedBy($factor)->toScale(4, RoundingMode::HALF_UP);
                $priceFactor = BigDecimal::of($poLine->price_stock_factor ?? $poLine->uom_factor);
                $priceDenominator = BigDecimal::of($poLine->price_quantity ?? '1');
                $provisionalAmount = $hasExplicitCost
                    ? BigDecimal::of((string) $input['received_qty'])->multipliedBy((string) $input['provisional_unit_cost'])->toScale(4, RoundingMode::HALF_UP)
                    : $stockQty->multipliedBy($poLine->unit_price)->dividedBy($priceFactor->multipliedBy($priceDenominator), 4, RoundingMode::HALF_UP);
                // An issued price is per purchase unit, not automatically per received unit.
                if (! $hasExplicitCost) {
                    $input['provisional_unit_cost'] = (string) BigDecimal::of($poLine->unit_price)
                        ->multipliedBy($factor)->dividedBy($priceFactor->multipliedBy($priceDenominator), 4, RoundingMode::HALF_UP);
                }
                $baseUnitCost = BigDecimal::of((string) $input['provisional_unit_cost'])
                    ->multipliedBy(BigDecimal::of((string) $input['fx_rate']))->toScale(4, RoundingMode::HALF_UP);

                $receipt->lines()->create(array_merge($input, [
                    'item_packaging_id' => $packageId, 'package_name' => $packaged ? $poLine->package_name : null,
                    'received_package_qty' => $packaged ? $input['received_qty'] : null, 'provisional_amount' => (string) $provisionalAmount,
                    'stock_qty' => (string) $stockQty, 'stock_uom_id' => $stockUom->id,
                    'uom_factor' => (string) $factor, 'base_unit_cost' => (string) $baseUnitCost,
                ]));
            }

            return $receipt->refresh()->load('lines');
        });
    }
}

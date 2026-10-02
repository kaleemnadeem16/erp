<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Actions\ItemUomConversionService;
use App\Modules\Inventory\Models\Item;
use App\Modules\Inventory\Models\ItemPackaging;
use App\Modules\Inventory\Models\ItemQuantityUnit;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\SupplierOffer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class BuildPurchaseOrderLineAttributes
{
    public function __construct(private readonly ItemUomConversionService $conversion) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function __invoke(PurchaseOrder $order, array $input, bool $preview = false): array
    {
        $item = Item::query()->findOrFail($input['item_id']);
        $purchaseUom = Uom::query()->findOrFail($input['purchase_uom_id']);
        $qty = BigDecimal::of((string) $input['ordered_qty']);
        try {
            // Item-specific item_quantity_units resolves first (may cross UoM
            // dimension), falling back to the global, dimension-locked service —
            // see ADR 0050.
            $factor = $this->conversion->stockUnitFactor($item, $purchaseUom);
        } catch (InvalidArgumentException) {
            throw new DomainInvariantException(
                'Purchase UoM must have the same dimension as the item stock UoM.',
                'purchase_uom_id',
            );
        }
        $package = empty($input['item_packaging_id']) ? null
            : ItemPackaging::query()->findOrFail($input['item_packaging_id']);
        $offer = empty($input['supplier_offer_id']) ? null
            : SupplierOffer::query()->with('relationship')->findOrFail($input['supplier_offer_id']);
        if ($offer) {
            if (! $offer->active || ! $offer->relationship->active || $offer->relationship->item_id !== $item->id
                || $offer->relationship->supplier_account_id !== $order->supplier_account_id
                || $offer->item_packaging_id !== ($package?->id)
                || $offer->purchase_uom_id !== $purchaseUom->id || $offer->currency_id !== $order->currency_id
                || ($offer->valid_from && $order->order_date->lt($offer->valid_from))
                || ($offer->valid_to && $order->order_date->gt($offer->valid_to))) {
                throw new DomainInvariantException('Offer is not applicable to this item, supplier, package, currency or date.', 'supplier_offer_id');
            }
        }
        if ($package) {
            if (! $package->active || $package->item_id !== $item->id || $purchaseUom->id !== $item->stock_uom_id) {
                throw new DomainInvariantException('Package does not match the item and stock-unit reference.', 'item_packaging_id');
            }
            $factor = BigDecimal::of($package->stock_quantity);
        }
        $increment = $package?->quantity_precision ?? ItemQuantityUnit::query()
            ->where('item_id', $item->id)->where('uom_id', $purchaseUom->id)->value('quantity_precision');
        if ($increment !== null && ! $qty->remainder($increment)->isZero()) {
            throw new DomainInvariantException('Order quantity violates the selected package/unit increment.', 'ordered_qty');
        }
        $exactStockQty = $qty->multipliedBy($factor);
        if ($item->quantity_precision !== null && ! $exactStockQty->remainder($item->quantity_precision)->isZero()) {
            throw new DomainInvariantException('Order quantity violates the item stock increment.', 'ordered_qty');
        }
        $stockQty = $exactStockQty->toScale(4, RoundingMode::HALF_UP);
        $priceUomId = array_key_exists('price_uom_id', $input) ? $input['price_uom_id'] : $offer?->price_uom_id;
        $priceUom = $priceUomId ? Uom::query()->findOrFail($priceUomId) : null;
        try {
            $priceFactor = $priceUom ? $this->conversion->stockUnitFactor($item, $priceUom) : $factor;
        } catch (InvalidArgumentException) {
            throw new DomainInvariantException('No fixed conversion exists for the pricing unit.', 'price_uom_id');
        }
        $priceQuantity = BigDecimal::of((string) ($input['price_quantity'] ?? $offer?->price_quantity ?? '1'));
        if (! $priceQuantity->isPositive()) {
            throw new DomainInvariantException('Price denominator must be positive.', 'price_quantity');
        }
        $pricedQty = $stockQty->dividedBy($priceFactor, 9, RoundingMode::HALF_UP);
        $minimum = $offer?->minimum_order_qty;
        $multiple = $offer?->order_multiple;
        $violatesTerms = ($minimum !== null && $qty->isLessThan($minimum))
            || ($multiple !== null && ! $qty->remainder($multiple)->isZero());
        if (! $preview && $violatesTerms && trim((string) ($input['ordering_exception_reason'] ?? '')) === '') {
            throw new DomainInvariantException('Supplier minimum or order multiple is not met. Enter a reason to continue.', 'ordering_exception_reason');
        }
        $input = array_merge($input, [
            'package_name' => $package?->name, 'package_code' => $package?->code,
            'package_increment' => $increment, 'order_unit_label' => $package?->name ?? $purchaseUom->code,
            'price_uom_id' => $priceUom?->id, 'price_unit_label' => $priceUom?->code ?? $package?->name ?? $purchaseUom->code,
            'price_quantity' => (string) $priceQuantity, 'price_stock_factor' => (string) $priceFactor,
            'priced_qty' => (string) $pricedQty, 'minimum_order_qty' => $minimum, 'order_multiple' => $multiple,
            'offer_snapshot' => $offer ? array_merge($offer->getAttributes(), ['supplier_account_id' => $order->supplier_account_id, 'item_id' => $item->id, 'constraint_warning' => $violatesTerms]) : null,
        ]);
        $unitPrice = BigDecimal::of((string) $input['unit_price']);
        $discount = BigDecimal::of((string) ($input['discount_amount'] ?? '0'));
        $tax = BigDecimal::of((string) ($input['tax_amount'] ?? '0'));
        $grossAmount = $stockQty->multipliedBy($unitPrice)->dividedBy($priceFactor->multipliedBy($priceQuantity), 9, RoundingMode::HALF_UP);
        if ($discount->isGreaterThan($grossAmount)) {
            throw new DomainInvariantException('Discount amount cannot exceed the gross line amount.', 'discount_amount');
        }
        $lineAmount = $grossAmount->minus($discount)->plus($tax)->toScale(4, RoundingMode::HALF_UP);
        $fx = BigDecimal::of($order->fx_rate);

        return array_merge($input, [
            'item_code' => null,
            'supplier_item_code' => $offer?->supplier_item_code,
            'ordered_stock_qty' => (string) $stockQty,
            'stock_uom_id' => $item->stock_uom_id,
            'uom_factor' => (string) $factor,
            'line_amount' => (string) $lineAmount,
            'base_unit_price' => (string) $unitPrice->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
            'base_discount_amount' => (string) $discount->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
            'base_tax_amount' => (string) $tax->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
            'base_line_amount' => (string) $lineAmount->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
            'short_closed_stock_qty' => '0',
        ]);
    }
}

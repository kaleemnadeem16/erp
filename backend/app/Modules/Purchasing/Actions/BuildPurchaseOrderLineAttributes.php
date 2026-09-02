<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\Uom;
use App\Modules\Inventory\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\UomConversionService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class BuildPurchaseOrderLineAttributes
{
    public function __construct(private readonly UomConversionService $conversion) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function __invoke(PurchaseOrder $order, array $input): array
    {
        $item = Item::query()->findOrFail($input['item_id']);
        $purchaseUom = Uom::query()->findOrFail($input['purchase_uom_id']);
        $stockUom = Uom::query()->findOrFail($item->stock_uom_id);
        $qty = BigDecimal::of((string) $input['ordered_qty']);
        $factor = $this->conversion->convert('1', $purchaseUom, $stockUom);
        $stockQty = $qty->multipliedBy($factor)->toScale(4, RoundingMode::HALF_UP);
        $unitPrice = BigDecimal::of((string) $input['unit_price']);
        $discount = BigDecimal::of((string) ($input['discount_amount'] ?? '0'));
        $tax = BigDecimal::of((string) ($input['tax_amount'] ?? '0'));
        $grossAmount = $qty->multipliedBy($unitPrice);
        if ($discount->isGreaterThan($grossAmount)) {
            throw new DomainInvariantException('Discount amount cannot exceed the gross line amount.', 'discount_amount');
        }
        $lineAmount = $grossAmount->minus($discount)->plus($tax)->toScale(4, RoundingMode::HALF_UP);
        $fx = BigDecimal::of($order->fx_rate);

        return array_merge($input, [
            'item_code' => null,
            'supplier_item_code' => null,
            'ordered_stock_qty' => (string) $stockQty,
            'stock_uom_id' => $stockUom->id,
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

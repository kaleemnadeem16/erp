<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Actions;

use App\Exceptions\DomainInvariantException;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Support\PurchaseOrderStatus;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class UpdatePurchaseOrder
{
    public function __construct(private readonly BuildPurchaseOrderLineAttributes $buildLine) {}

    /** @param array<string, mixed> $attributes */
    public function __invoke(PurchaseOrder $order, array $attributes): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $attributes): PurchaseOrder {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== PurchaseOrderStatus::Draft) {
                throw new DomainInvariantException('Only a draft purchase order can be edited.', 'status');
            }
            $lines = Arr::pull($attributes, 'lines');
            $order->fill($attributes);
            $baseAmountsChanged = $order->isDirty(['currency_id', 'fx_rate']);
            $order->save();
            if (is_array($lines)) {
                $order->lines()->delete();
                foreach ($lines as $line) {
                    $order->lines()->create(($this->buildLine)($order, $line));
                }
            } elseif ($baseAmountsChanged) {
                $fx = BigDecimal::of($order->fx_rate);
                foreach ($order->lines()->lockForUpdate()->get() as $line) {
                    $line->update([
                        'base_unit_price' => (string) BigDecimal::of($line->unit_price)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                        'base_discount_amount' => (string) BigDecimal::of($line->discount_amount)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                        'base_tax_amount' => (string) BigDecimal::of($line->tax_amount)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                        'base_line_amount' => (string) BigDecimal::of($line->line_amount)->multipliedBy($fx)->toScale(4, RoundingMode::HALF_UP),
                    ]);
                }
            }

            return $order->refresh()->load('lines');
        });
    }
}

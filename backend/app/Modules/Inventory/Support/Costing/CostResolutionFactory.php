<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

use App\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Internal material resolution construction shared by registered valuation strategies. */
final class CostResolutionFactory
{
    /** @param list<array<string, mixed>> $basis
     * @param  array<string, mixed>|null  $scopeUsed
     */
    public function make(MaterialCostRequest $request, CostReadSession $session, BigDecimal $covered, ?BigDecimal $known, array $basis = [], ?string $reason = null, ?array $scopeUsed = null): CostResolution
    {
        $complete = $covered->isEqualTo($request->quantity) && $reason === null;
        $status = $complete ? CostStatus::Costed : ($known === null ? CostStatus::Unknown : CostStatus::Partial);
        $known = $complete ? ($known ?? BigDecimal::zero())->toScale(4, RoundingMode::HALF_UP) : $known?->toScale(4, RoundingMode::HALF_UP);

        return new CostResolution(
            $status, $complete ? $known : null,
            $complete && ! $request->quantity->isZero() ? $known?->dividedBy($request->quantity, 4, RoundingMode::HALF_UP) : null,
            $known, $request->amountBasis, $request->quantity,
            Company::query()->findOrFail($request->item->company_id)->base_currency_id, $request->item->stock_uom_id,
            [['item_id' => $request->item->id, 'uom_id' => $request->item->stock_uom_id, 'required_quantity' => (string) $request->quantity,
                'costed_quantity' => (string) $covered, 'unresolved_quantity' => (string) $request->quantity->minus($covered)]],
            $reason === null ? [] : [['code' => $reason]],
            'INVENTORY_VALUATION', $request->item->valuation_method->value,
            $request->scope(), $scopeUsed ?? $request->scope(), $request->asOf?->toIso8601String(),
            $session->observedAt->toIso8601String(), $session->observedAt->toIso8601String(), $basis,
        );
    }

    public function unknown(MaterialCostRequest $request, CostReadSession $session, string $reason): CostResolution
    {
        return $this->make($request, $session, BigDecimal::zero(), null, reason: $reason);
    }
}

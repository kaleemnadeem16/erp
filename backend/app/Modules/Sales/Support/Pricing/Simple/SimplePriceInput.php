<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Simple;

use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Financial\StorageAmount;
use App\Modules\Sales\Support\Fx\FinancialDate;
use Brick\Math\BigDecimal;

/** Internal selected price, not eligibility or discretionary authority. The owning adapter must first prove the source/quantity and effective entry limits. */
final readonly class SimplePriceInput
{
    public BigDecimal $quantity;

    public BigDecimal $adjustmentValue;

    public function __construct(
        public int $companyId,
        public int $currencyId,
        public string $lineKey,
        public string $sourceReference,
        public string $pricingDate,
        public ?string $unitPrice,
        string $quantity,
        public SimpleAdjustment $adjustment = SimpleAdjustment::None,
        string $adjustmentValue = '0',
    ) {
        if ($companyId < 1 || $currencyId < 1 || trim($lineKey) === '' || trim($sourceReference) === '') {
            throw FinancialRefusal::make('SIMPLE_PRICE_INVALID', 'A selected price needs company, currency, stable line and source identities.');
        }
        FinancialDate::assertCalendarDate($pricingDate, 'pricing_date');
        $this->quantity = Arithmetic::quantity($quantity);
        if (! $this->quantity->isPositive()) {
            throw FinancialRefusal::make('SIMPLE_PRICE_INVALID', 'A priced quantity must be positive.');
        }
        if (preg_match('/^\d+(\.\d+)?$/', $adjustmentValue) !== 1) {
            throw FinancialRefusal::make('SIMPLE_ADJUSTMENT_INVALID', 'An adjustment must be a non-negative plain decimal.');
        }
        $value = BigDecimal::of($adjustmentValue);
        if ($adjustment === SimpleAdjustment::Percent) {
            if ($value->isGreaterThan(100) || $value->strippedOfTrailingZeros()->getScale() > 9) {
                throw FinancialRefusal::make('SIMPLE_ADJUSTMENT_INVALID', 'A percentage is at most 100 with at most nine decimal places.');
            }
        } else {
            $value = StorageAmount::of($value, 'adjustment');
        }
        if ($adjustment === SimpleAdjustment::None && ! $value->isZero()) {
            throw FinancialRefusal::make('SIMPLE_ADJUSTMENT_INVALID', 'No adjustment cannot carry a benefit.');
        }
        $this->adjustmentValue = $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Pricing\Simple;

use App\Modules\Sales\Support\Financial\Arithmetic;
use App\Modules\Sales\Support\Financial\Controls\ControlBasis;
use App\Modules\Sales\Support\Financial\Controls\DiscretionaryInput;
use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Fx\FinancialDate;
use Brick\Math\BigDecimal;

/** Internal selected price, not eligibility or discretionary authority. The owning adapter must first prove the source/quantity and effective entry limits. */
final readonly class SimplePriceInput
{
    public BigDecimal $quantity;

    public BigDecimal $adjustmentValue;

    public DiscretionaryInput $discretionary;

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
        if ($adjustment === SimpleAdjustment::None && ! BigDecimal::of($adjustmentValue)->isZero()) {
            throw FinancialRefusal::make('SIMPLE_ADJUSTMENT_INVALID', 'No adjustment cannot carry a benefit.');
        }
        $this->discretionary = match ($adjustment) {
            SimpleAdjustment::None => DiscretionaryInput::none(),
            SimpleAdjustment::Percent => DiscretionaryInput::percent($adjustmentValue),
            SimpleAdjustment::PerUnit => DiscretionaryInput::amount($adjustmentValue, $currencyId, ControlBasis::PerUnit),
            SimpleAdjustment::PerLine => DiscretionaryInput::amount($adjustmentValue, $currencyId, ControlBasis::Line),
        };
        $this->adjustmentValue = $this->discretionary->value ?? BigDecimal::zero();
    }
}

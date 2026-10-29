<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Calculation;

use App\Modules\Sales\Support\Financial\FinancialRefusal;
use App\Modules\Sales\Support\Pricing\Simple\SimplePriceInput;
use App\Modules\Tax\Support\Calculation\BasicTaxInput;

/** One explicitly selected chargeable logical line, never every alternative or an explanatory child. Selection/authorisation belongs to the owning workflow. */
final readonly class QuotationFinancialLine
{
    public function __construct(public SimplePriceInput $price, public BasicTaxInput $tax)
    {
        if ($price->companyId !== $tax->companyId) {
            throw FinancialRefusal::make('FINANCIAL_CONTEXT_MISMATCH', 'Price and tax must belong to the same company.');
        }
    }
}

<?php

declare(strict_types=1);
namespace App\Modules\Tax\Support\Quotation;
use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Financial\CurrencyQuantum;
use App\Modules\Sales\Support\Financial\Policy\SettlementRounding;
use App\Modules\Tax\Support\TaxRefusal;

/** Tax-stage versioning, distinct from Sales' LINE_NET policy. Reuses FR4 arithmetic, never re-rounds net. */
final readonly class QuotationTaxSettlementPolicy
{
    public const VERSION='FR3.1';
    public SettlementRule $rule;
    /** @param array<string,int|string|null> $pins */
    public function __construct(array $pins)
    {
        $rounding=SettlementRounding::tryFrom((string)($pins['rounding_mode']??''));
        if (($pins['calculation_version']??null)!==self::VERSION || ($pins['settlement_basis']??null)!=='LINE_COMPONENT'
            || (int)($pins['declaration_version_id']??0)<1 || !is_int($pins['currency_places']??null)
            || (int)($pins['currency_id']??0)<1 || $rounding===null) {
            throw TaxRefusal::because('TAX_CALCULATION_UNSUPPORTED','An explicit versioned line-component Tax policy is required.');
        }
        $this->rule=new SettlementRule((int)$pins['currency_id'],new CurrencyQuantum($pins['currency_places']),$rounding->mode());
    }
}

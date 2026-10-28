<?php

declare(strict_types=1);
namespace App\Modules\Tax\Support\Quotation;
use App\Modules\Tax\Support\EffectivePeriod;
use App\Modules\Tax\Support\TaxClassification;
use App\Modules\Tax\Support\TaxRefusal;

/** Server-selected source facts, never an HTTP/profile selector. The consumer must prove them. */
final readonly class QuotationTaxContext
{
    public function __construct(public int $companyId, public int $partyId, public int $currencyId,
        public string $lineReference, public string $jurisdiction, public string $taxDate, public TaxClassification $classification)
    {
        new EffectivePeriod($taxDate);
        if ($companyId < 1 || $partyId < 1 || $currencyId < 1 || trim($lineReference) === '' || strlen($lineReference) > 120 || trim($jurisdiction) === '' || strlen($jurisdiction) > 80) {
            throw TaxRefusal::because('TAX_CONTEXT_INVALID', 'Invalid quotation tax context.');
        }
    }
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->companyId,$this->partyId,$this->currencyId,$this->lineReference,$this->jurisdiction,$this->taxDate,
            $this->classification->source->value,$this->classification->nature?->value,$this->classification->code,$this->classification->provenance], JSON_THROW_ON_ERROR));
    }
    /** @return array<string, mixed> */
    public function __debugInfo(): array { return []; }
}

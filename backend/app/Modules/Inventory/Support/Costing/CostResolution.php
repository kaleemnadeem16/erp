<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** One immutable value for complete amounts, known contributions and copied supporting facts. */
final readonly class CostResolution
{
    /**
     * @param  list<array<string, mixed>>  $coverage
     * @param  list<array<string, mixed>>  $diagnostics
     * @param  list<array<string, mixed>>  $basis
     * @param  array<string, mixed>  $scopeRequested
     * @param  array<string, mixed>  $scopeUsed
     */
    public function __construct(
        public CostStatus $status,
        public ?BigDecimal $totalCost,
        public ?BigDecimal $unitCost,
        public ?BigDecimal $knownCost,
        public string $amountBasis,
        public BigDecimal $valuedQuantity,
        public ?int $currencyId,
        public ?int $unitBasis,
        public array $coverage,
        public array $diagnostics,
        public string $sourceType,
        public string $method,
        public array $scopeRequested,
        public array $scopeUsed,
        public ?string $requestedAsOf,
        public string $effectiveAsOf,
        public string $observedAt,
        public array $basis,
    ) {
        foreach ([$totalCost, $unitCost, $knownCost] as $amount) {
            if ($amount !== null && ($amount->isNegative() || $amount->isGreaterThan('999999999999999.9999'))) {
                throw new DomainInvariantException('Cost amount exceeds decimal(19,4) bounds.', 'cost');
            }
        }
        if (($status === CostStatus::Costed) !== ($totalCost !== null)
            || ($status !== CostStatus::Costed && $unitCost !== null)
            || ($status === CostStatus::Unknown && $knownCost !== null)
            || ($status !== CostStatus::Unknown && $knownCost === null)) {
            throw new \InvalidArgumentException('Cost certainty and amounts disagree.');
        }
    }

    /** @return array<string, mixed> Cost evidence must be projected only by an authorised consumer. */
    public function toArray(): array
    {
        $facts = [
            'schema_version' => 1, 'status' => $this->status->value,
            'total_cost' => $this->money($this->totalCost), 'unit_cost' => $this->money($this->unitCost),
            'known_cost' => $this->money($this->knownCost), 'amount_basis' => $this->amountBasis,
            'valued_quantity' => (string) $this->valuedQuantity,
            'currency_id' => $this->currencyId, 'unit_basis' => $this->unitBasis,
            'coverage' => $this->coverage, 'diagnostics' => $this->diagnostics,
            'source_type' => $this->sourceType, 'method' => $this->method,
            'scope_requested' => $this->scopeRequested, 'scope_used' => $this->scopeUsed,
            'scope_changed' => $this->scopeRequested !== $this->scopeUsed,
            'requested_as_of' => $this->requestedAsOf, 'effective_as_of' => $this->effectiveAsOf,
            'observed_at' => $this->observedAt, 'basis' => $this->basis,
        ];
        // Clock ticks alone are not a change of valuation evidence.
        $fingerprintFacts = $facts;
        unset($fingerprintFacts['observed_at'], $fingerprintFacts['effective_as_of']);
        $facts['basis_fingerprint'] = hash('sha256', json_encode($fingerprintFacts, JSON_THROW_ON_ERROR));

        return $facts;
    }

    private function money(?BigDecimal $amount): ?string
    {
        return $amount === null ? null : (string) $amount->toScale(4, RoundingMode::HALF_UP);
    }
}

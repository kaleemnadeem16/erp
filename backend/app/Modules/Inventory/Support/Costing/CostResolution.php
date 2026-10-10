<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

use App\Exceptions\DomainInvariantException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** One immutable value for complete amounts, known contributions and copied supporting facts. */
final readonly class CostResolution
{
    /** Stock identity and quantities that reveal what is held where; recursive because roll-ups nest facts. */
    private const STOCK_DETAIL_KEYS = [
        'pool_id', 'layer_id', 'location_id', 'batch_id', 'serial_no', 'warehouse_id', 'qty_on_hand', 'inventory_value',
        'quantity', 'quantity_available', 'received_at', 'source_document_type', 'source_id', 'source_line_no', 'basis_fingerprint',
    ];

    /**
     * @param  list<array{item_id: int, uom_id: int, required_quantity: string, costed_quantity: string|null, unresolved_quantity: string|null}>  $coverage
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

    /**
     * The same value as seen by a reader who may not see stock. Pools, layers, locations, batches, serials,
     * on-hand quantities and source documents are removed (also from nested roll-up facts), and the covered
     * quantities that would reveal what is held are withheld, so the fingerprint cannot move with stock.
     */
    public function forReader(bool $stockDetail): self
    {
        if ($stockDetail) {
            return $this;
        }

        return new self($this->status, $this->totalCost, $this->unitCost, $this->knownCost, $this->amountBasis,
            $this->valuedQuantity, $this->currencyId, $this->unitBasis,
            array_map(fn (array $row): array => [...$row, 'costed_quantity' => null, 'unresolved_quantity' => null], $this->coverage),
            $this->withoutStockDetail($this->diagnostics), $this->sourceType, $this->method,
            $this->withoutStockDetail($this->scopeRequested), $this->withoutStockDetail($this->scopeUsed),
            $this->requestedAsOf, $this->effectiveAsOf, $this->observedAt, $this->withoutStockDetail($this->basis));
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
        $facts['basis_fingerprint'] = hash('sha256', json_encode($this->withoutObservationTimes($facts), JSON_THROW_ON_ERROR));

        return $facts;
    }

    /** @param array<mixed> $facts
     * @return array<mixed>
     */
    private function withoutObservationTimes(array $facts): array
    {
        unset($facts['observed_at'], $facts['effective_as_of']);
        foreach ($facts as $key => $value) {
            if (is_array($value)) {
                $facts[$key] = $this->withoutObservationTimes($value);
            }
        }

        return $facts;
    }

    /** @param array<mixed> $facts
     * @return array<mixed>
     */
    private function withoutStockDetail(array $facts): array
    {
        foreach (self::STOCK_DETAIL_KEYS as $key) {
            unset($facts[$key]);
        }
        foreach (['costed_quantity', 'unresolved_quantity'] as $key) {
            if (array_key_exists($key, $facts)) {
                $facts[$key] = null;
            }
        }
        foreach ($facts as $key => $value) {
            if (is_array($value)) {
                $facts[$key] = $this->withoutStockDetail($value);
            }
        }

        return $facts;
    }

    private function money(?BigDecimal $amount): ?string
    {
        return $amount === null ? null : (string) $amount->toScale(4, RoundingMode::HALF_UP);
    }
}

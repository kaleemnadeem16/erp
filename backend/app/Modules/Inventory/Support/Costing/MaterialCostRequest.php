<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Support\Costing;

use App\Exceptions\DomainInvariantException;
use App\Modules\Inventory\Models\Item;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

/** Internal stock quantity; public technical adapters retain their existing precision. */
final readonly class MaterialCostRequest
{
    public BigDecimal $quantity;

    public function __construct(
        public Item $item,
        string|BigDecimal $quantity,
        public ?int $warehouseId = null,
        public ?int $locationId = null,
        public ?int $batchId = null,
        public ?string $serialNo = null,
        public ?CarbonImmutable $asOf = null,
        public string $amountBasis = 'REQUIRED_QUANTITY',
    ) {
        $this->quantity = BigDecimal::of($quantity);
        if ($this->quantity->isNegative() || $this->quantity->isGreaterThan('999999999999.999999999')
            || $this->quantity->stripTrailingZeros()->getScale() > 9) {
            throw new DomainInvariantException('Cost quantity requires at most twelve integer and nine fractional digits.', 'quantity');
        }
    }

    /** @return array{type: string, warehouse_id: int|null, location_id: int|null, batch_id: int|null, serial_no: string|null} */
    public function scope(): array
    {
        return [
            'type' => $this->locationId !== null || $this->batchId !== null || $this->serialNo !== null ? 'CHOSEN_STOCK' : ($this->warehouseId === null ? 'COMPANY' : 'WAREHOUSE'),
            'warehouse_id' => $this->warehouseId, 'location_id' => $this->locationId,
            'batch_id' => $this->batchId, 'serial_no' => $this->serialNo,
        ];
    }
}

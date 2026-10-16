<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Allocation;

use App\Modules\Sales\Support\Financial\SettlementRule;
use App\Modules\Sales\Support\Kernel\OperationIntent;
use Brick\Math\BigDecimal;

/**
 * The frozen result of allocating one settled amount across components: the total that was placed, the currency quantum it was placed in, the method and
 * tie rule, and one `AllocatedShare` per component in canonical key order. The shares always sum to the total exactly. Everything is decimal strings or
 * exact fractions, so it can be stored as evidence and compared; `fingerprint()` is the same for the same inputs however the components were ordered.
 */
final readonly class FinancialAllocation
{
    public const METHOD = 'LARGEST_REMAINDER';

    public const TIE_POLICY = 'KEY_ASCENDING';

    public const CALCULATION_VERSION = 'FR4.2';

    /** @param  list<AllocatedShare>  $shares  in ascending key order */
    public function __construct(public BigDecimal $total, public SettlementRule $rule, public array $shares)
    {
        $sum = BigDecimal::zero();
        foreach ($shares as $share) {
            $sum = $sum->plus($share->allocated);
        }
        if (! $sum->isEqualTo($total)) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_INCONSISTENT, 'The allocation does not add up to its total.');
        }
    }

    public function for(string $key): ?AllocatedShare
    {
        foreach ($this->shares as $share) {
            if ($share->key === $key) {
                return $share;
            }
        }

        return null;
    }

    public function sum(): BigDecimal
    {
        return $this->total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'calculation_version' => self::CALCULATION_VERSION,
            'method' => self::METHOD,
            'tie_policy' => self::TIE_POLICY,
            'currency_id' => $this->rule->currencyId,
            'quantum_places' => $this->rule->quantum->decimalPlaces,
            'total' => (string) $this->total,
            'shares' => array_map(static fn (AllocatedShare $share): array => $share->toArray(), $this->shares),
        ];
    }

    public function fingerprint(): string
    {
        return OperationIntent::hash('fr4.allocation', $this->toArray());
    }
}

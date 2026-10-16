<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Allocation;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * What one component ended up with, and why. `raw` is the exact proportional share (the computed value, a fraction); `allocated` is the whole number of
 * quanta it was given (the settled value); `residual` is allocated minus raw. Nothing here is rounded: the difference between computed and allocated is
 * kept as evidence, never absorbed.
 */
final readonly class AllocatedShare
{
    public function __construct(
        public string $key,
        public bool $eligible,
        public ?string $reason,
        public ?BigDecimal $weight,
        public ?BigDecimal $cap,
        public BigRational $raw,
        public BigDecimal $allocated,
        public bool $gotExtraQuantum,
        public bool $atCap,
    ) {}

    public function residual(): BigRational
    {
        return BigRational::of($this->allocated)->minus($this->raw);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $raw = $this->raw->simplified();
        $residual = $this->residual()->simplified();

        return [
            'key' => $this->key,
            'eligible' => $this->eligible,
            'reason' => $this->reason,
            'weight' => $this->weight === null ? null : (string) $this->weight,
            'cap' => $this->cap === null ? null : (string) $this->cap,
            'raw_share' => ['numerator' => (string) $raw->getNumerator(), 'denominator' => (string) $raw->getDenominator()],
            'allocated' => (string) $this->allocated,
            'residual' => ['numerator' => (string) $residual->getNumerator(), 'denominator' => (string) $residual->getDenominator()],
            'extra_quantum' => $this->gotExtraQuantum,
            'at_cap' => $this->atCap,
        ];
    }
}

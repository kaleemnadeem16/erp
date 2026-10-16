<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support\Financial\Allocation;

use App\Modules\Sales\Support\Financial\StorageAmount;
use Brick\Math\BigDecimal;

/**
 * One component an amount may be allocated to, identified by its stable logical key (the quotation's logical line identity, not its position on screen).
 * It is either ELIGIBLE, with a non-negative weight and an optional cap on what it may take, or INELIGIBLE with a reason (exempt, not chargeable, unknown
 * base). An ineligible component is never given money and never silently becomes eligible; a caller who does not know a component's base says so by
 * marking it ineligible with that reason, not by passing zero.
 */
final readonly class AllocationShare
{
    public const MAX_WEIGHT_SCALE = 9;

    private function __construct(
        public string $key,
        public bool $eligible,
        public ?BigDecimal $weight,
        public ?BigDecimal $cap,
        public ?string $reason,
    ) {}

    /** @param  string|BigDecimal|null  $cap  the most this component may take, as a non-negative amount; null for none */
    public static function eligible(string $key, string|BigDecimal $weight, string|BigDecimal|null $cap = null): self
    {
        return new self(self::key($key), true, self::decimal($weight, 'weight', self::MAX_WEIGHT_SCALE), $cap === null ? null : self::decimal($cap, 'cap', StorageAmount::SCALE), null);
    }

    public static function ineligible(string $key, string $reason): self
    {
        if (trim($reason) === '') {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_COMPONENT_INVALID, 'An ineligible component must say why.', 'reason');
        }

        return new self(self::key($key), false, null, null, trim($reason));
    }

    private static function key(string $key): string
    {
        if ($key === '' || trim($key) !== $key) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_COMPONENT_INVALID, 'A component needs a non-empty key without surrounding spaces.', 'key');
        }

        return $key;
    }

    private static function decimal(string|BigDecimal $value, string $field, int $maxScale): BigDecimal
    {
        $text = (string) $value;
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_COMPONENT_INVALID, "The {$field} must be a plain non-negative decimal.", $field);
        }
        $decimal = BigDecimal::of($text);
        if ($decimal->strippedOfTrailingZeros()->getScale() > $maxScale || $decimal->isGreaterThan(StorageAmount::MAX)) {
            throw AllocationRefusal::make(AllocationRefusal::ALLOCATION_COMPONENT_INVALID, "The {$field} has more places or a larger size than is supported.", $field);
        }

        return $decimal;
    }
}

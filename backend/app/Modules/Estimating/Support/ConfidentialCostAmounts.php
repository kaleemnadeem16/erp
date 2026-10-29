<?php

declare(strict_types=1);

namespace App\Modules\Estimating\Support;

use Brick\Math\BigDecimal;
use Closure;
use LogicException;

/**
 * The confidential figures behind a piece of cost evidence, handed only to an authorised workflow. They are kept inside a closure so that `var_export`, `print_r`,
 * `var_dump`, `json_encode` and `serialize` show nothing: there is no property to export, `jsonSerialize` and `__debugInfo` are empty and serialising throws.
 * Short-lived by design — the trusted evaluator reads what it needs and lets it go.
 */
final class ConfidentialCostAmounts implements \JsonSerializable
{
    /** @param  Closure(): array{total: BigDecimal|null, known: BigDecimal|null, currency: int|null}  $reveal */
    public function __construct(private readonly Closure $reveal) {}

    /** The complete cost, or null when it is not complete. */
    public function total(): ?BigDecimal
    {
        return ($this->reveal)()['total'];
    }

    /** What is known so far: the complete cost, or a lower bound that is NOT the cost when the evidence is partial. */
    public function known(): ?BigDecimal
    {
        return ($this->reveal)()['known'];
    }

    public function currencyId(): ?int
    {
        return ($this->reveal)()['currency'];
    }

    /** @return array<string, never> */
    public function jsonSerialize(): array
    {
        return [];
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Cost amounts are not serialisable.');
    }

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Cost amounts are not serialisable.');
    }
}

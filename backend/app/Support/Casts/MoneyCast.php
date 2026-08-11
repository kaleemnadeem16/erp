<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

/**
 * Casts a decimal(19,4) column to/from a Brick\Money\Money value object, paired with a
 * sibling currency column on the same model — see invariants.md#6 (money is decimal(19,4),
 * never float) and #5 (every monetary row also stores its currency).
 *
 * Usage: protected function casts(): array { return ['amount' => MoneyCast::class.':currency']; }
 *
 * @implements CastsAttributes<Money, Money>
 */
final class MoneyCast implements CastsAttributes
{
    public function __construct(private readonly string $currencyColumn = 'currency') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        $currency = $attributes[$this->currencyColumn] ?? null;

        if ($currency === null) {
            throw new RuntimeException(
                "MoneyCast on [{$key}] requires a sibling currency column [{$this->currencyColumn}] to be selected/set."
            );
        }

        return Money::of($value, $currency, roundingMode: RoundingMode::UNNECESSARY);
    }

    /**
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        // @implements CastsAttributes<Money, Money> above tells PHPStan $value is already
        // Money here, but that's a static-analysis convenience — Eloquent calls set() with
        // mixed at runtime, so this guards against a caller actually passing something else.
        if (! $value instanceof Money) { // @phpstan-ignore instanceof.alwaysTrue
            throw new InvalidArgumentException(
                "MoneyCast on [{$key}] expects a Brick\\Money\\Money instance, got ".get_debug_type($value)
            );
        }

        return [
            $key => (string) $value->getAmount()->toScale(4, RoundingMode::UNNECESSARY),
            $this->currencyColumn => $value->getCurrency()->getCurrencyCode(),
        ];
    }
}

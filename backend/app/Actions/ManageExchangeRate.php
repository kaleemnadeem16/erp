<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\DomainInvariantException;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/** The write boundary for manual rate maintenance, including non-HTTP callers. */
final class ManageExchangeRate
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): ExchangeRate
    {
        $this->authorize($actor);
        $attributes['rate'] = self::validRate($attributes['rate'] ?? null);

        return ExchangeRate::create([...$attributes, 'source' => 'manual']);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, ExchangeRate $rate, array $attributes): ExchangeRate
    {
        $this->authorize($actor);
        $this->assertCurrentCompany($rate);
        if (array_key_exists('rate', $attributes)) {
            $attributes['rate'] = self::validRate($attributes['rate']);
        }
        $rate->update($attributes);

        return $rate;
    }

    public function delete(User $actor, ExchangeRate $rate): void
    {
        $this->authorize($actor);
        $this->assertCurrentCompany($rate);
        $rate->delete();
    }

    /**
     * The one place a manual rate is judged, for every caller (HTTP, console, seeders): a plain decimal string, strictly greater than zero, at most ten
     * integer and nine fractional digits (decimal(19,9)). A zero or negative rate would silently zero or invert an amount, so it is refused rather than stored
     * (and the database refuses it too). Valid rates are returned exactly as given, never rounded.
     */
    public static function validRate(mixed $rate): string
    {
        if (! is_string($rate) || preg_match('/^\d{1,10}(\.\d{1,9})?$/', $rate) !== 1) {
            throw new DomainInvariantException('An exchange rate is a plain decimal with at most 10 whole and 9 fractional digits.', 'rate', 'EXCHANGE_RATE_INVALID');
        }
        if (BigDecimal::of($rate)->isZero()) {
            throw new DomainInvariantException('An exchange rate must be greater than zero.', 'rate', 'EXCHANGE_RATE_NOT_POSITIVE');
        }

        return $rate;
    }

    private function authorize(User $actor): void
    {
        if (! $actor->can('currency.exchange-rate.manage')) {
            throw new AuthorizationException;
        }
    }

    private function assertCurrentCompany(ExchangeRate $rate): void
    {
        if ($rate->company_id !== $this->companyContext->id()) {
            throw (new ModelNotFoundException)->setModel(ExchangeRate::class, [$rate->id]);
        }
    }
}

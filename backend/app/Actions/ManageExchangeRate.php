<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\CompanyContext;
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

        return ExchangeRate::create([...$attributes, 'source' => 'manual']);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, ExchangeRate $rate, array $attributes): ExchangeRate
    {
        $this->authorize($actor);
        $this->assertCurrentCompany($rate);
        $rate->update($attributes);

        return $rate;
    }

    public function delete(User $actor, ExchangeRate $rate): void
    {
        $this->authorize($actor);
        $this->assertCurrentCompany($rate);
        $rate->delete();
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeRate\IndexExchangeRateRequest;
use App\Http\Requests\ExchangeRate\StoreExchangeRateRequest;
use App\Http\Requests\ExchangeRate\UpdateExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use App\Models\ExchangeRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Manual exchange-rate entry only — see docs/decisions/0031-manual-exchange-rate-entry.md.
 * There is no other source yet, so store() always stamps source = 'manual' itself
 * rather than trusting client input, per that ADR's consistency requirement.
 */
class ExchangeRateController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'from_currency' => 'fromCurrency',
        'to_currency' => 'toCurrency',
    ];

    public function index(IndexExchangeRateRequest $request): AnonymousResourceCollection
    {
        $query = ExchangeRate::query();

        $this->applyIncludes($query, $request);

        if ($request->filled('filter.from_currency_id')) {
            $query->where('from_currency_id', $request->input('filter.from_currency_id'));
        }

        if ($request->filled('filter.to_currency_id')) {
            $query->where('to_currency_id', $request->input('filter.to_currency_id'));
        }

        if ($request->filled('filter.rate_type')) {
            $query->where('rate_type', $request->input('filter.rate_type'));
        }

        if ($request->filled('filter.rate_date')) {
            $query->whereDate('rate_date', $request->input('filter.rate_date'));
        }

        $query->orderByDesc('rate_date')->orderByDesc('id');

        $perPage = $request->integer('per_page', 25);

        return ExchangeRateResource::collection($query->paginate($perPage));
    }

    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        try {
            $rate = ExchangeRate::create([
                ...$request->validated(),
                'source' => 'manual',
            ]);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23505'
                || ! str_contains($exception->getMessage(), 'exchange_rates_unique_rate')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'rate_date' => ['A rate already exists for this currency pair, date, and rate type.'],
            ]);
        }

        return (new ExchangeRateResource($rate))->response()->setStatusCode(201);
    }

    public function show(Request $request, ExchangeRate $exchange_rate): ExchangeRateResource
    {
        return new ExchangeRateResource($exchange_rate->load(
            $this->requestedIncludes($request)
        ));
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchange_rate): ExchangeRateResource
    {
        $exchange_rate->update($request->validated());

        return new ExchangeRateResource($exchange_rate);
    }

    public function destroy(ExchangeRate $exchange_rate): JsonResponse
    {
        $exchange_rate->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<ExchangeRate>  $query
     */
    private function applyIncludes(Builder $query, Request $request): void
    {
        foreach ($this->requestedIncludes($request) as $relation) {
            $query->with($relation);
        }
    }

    /**
     * @return list<string>
     */
    private function requestedIncludes(Request $request): array
    {
        $requested = array_filter(explode(',', (string) $request->query('include', '')));

        return array_values(array_intersect_key(self::INCLUDABLE, array_flip($requested)));
    }
}

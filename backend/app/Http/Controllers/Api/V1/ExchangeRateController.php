<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\ManageExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeRate\IndexExchangeRateRequest;
use App\Http\Requests\ExchangeRate\ShowExchangeRateRequest;
use App\Http\Requests\ExchangeRate\StoreExchangeRateRequest;
use App\Http\Requests\ExchangeRate\UpdateExchangeRateRequest;
use App\Http\Resources\ExchangeRateResource;
use App\Models\ExchangeRate;
use App\Support\Concerns\AppliesApiQuery;
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
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'from_currency' => 'fromCurrency',
        'to_currency' => 'toCurrency',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'rate_date' => 'rate_date',
        'rate' => 'rate',
        'created_at' => 'created_at',
    ];

    public function index(IndexExchangeRateRequest $request): AnonymousResourceCollection
    {
        $query = ExchangeRate::query();

        $this->applyIncludes($query, $request, self::INCLUDABLE);

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

        if ($request->filled('sort')) {
            $this->applySort($query, $request, self::SORTABLE, 'rate_date');
        } else {
            // Preserve the endpoint's established newest-first default, including
            // deterministic ordering when multiple rates share the same date.
            $query->orderByDesc('rate_date')->orderByDesc('id');
        }

        $perPage = $request->integer('per_page', 25);

        return ExchangeRateResource::collection($query->paginate($perPage));
    }

    public function store(StoreExchangeRateRequest $request, ManageExchangeRate $manage): JsonResponse
    {
        try {
            $rate = $manage->create($request->user(), $request->validated());
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

    public function show(ShowExchangeRateRequest $request, ExchangeRate $exchange_rate): ExchangeRateResource
    {
        return new ExchangeRateResource($exchange_rate->load(
            $this->requestedIncludes($request, self::INCLUDABLE)
        ));
    }

    public function update(UpdateExchangeRateRequest $request, ExchangeRate $exchange_rate, ManageExchangeRate $manage): ExchangeRateResource
    {
        return new ExchangeRateResource($manage->update($request->user(), $exchange_rate, $request->validated()));
    }

    public function destroy(Request $request, ExchangeRate $exchange_rate, ManageExchangeRate $manage): JsonResponse
    {
        $manage->delete($request->user(), $exchange_rate);

        return response()->json(status: 204);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Currency\IndexCurrencyRequest;
use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Read-only global reference data for currency selectors. */
class CurrencyController extends Controller
{
    use AppliesApiQuery;

    private const SORTABLE = ['code' => 'code', 'name' => 'name'];

    public function index(IndexCurrencyRequest $request): AnonymousResourceCollection
    {
        $query = Currency::query();
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $query) => $query->where('code', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%"));
        }
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return CurrencyResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function show(Request $request, Currency $currency): CurrencyResource
    {
        return new CurrencyResource($currency);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Http\Requests\CustomerAccount\IndexCustomerAccountRequest;
use App\Modules\Parties\Http\Requests\CustomerAccount\StoreCustomerAccountRequest;
use App\Modules\Parties\Http\Requests\CustomerAccount\UpdateCustomerAccountRequest;
use App\Modules\Parties\Http\Resources\CustomerAccountResource;
use App\Modules\Parties\Models\CustomerAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerAccountController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'party' => 'party',
    ];

    public function index(IndexCustomerAccountRequest $request): AnonymousResourceCollection
    {
        $query = CustomerAccount::query();

        $this->applyIncludes($query, $request);

        if ($request->filled('filter.party_id')) {
            $query->where('party_id', $request->input('filter.party_id'));
        }

        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        if ($request->filled('filter.search')) {
            $query->where('code', 'ilike', '%'.$request->string('filter.search')->toString().'%');
        }

        $query->orderBy('code');

        $perPage = $request->integer('per_page', 25);

        return CustomerAccountResource::collection($query->paginate($perPage));
    }

    public function store(StoreCustomerAccountRequest $request): JsonResponse
    {
        $account = CustomerAccount::create($request->validated());

        // status/custom_fields fall back to a Postgres column default when
        // omitted — the in-memory model from create() never sees that value,
        // only the DB row does. See ItemController::store() for the same fix.
        $account->refresh();

        return (new CustomerAccountResource($account))->response()->setStatusCode(201);
    }

    public function show(Request $request, CustomerAccount $customer_account): CustomerAccountResource
    {
        return new CustomerAccountResource($customer_account->load($this->requestedIncludes($request)));
    }

    public function update(UpdateCustomerAccountRequest $request, CustomerAccount $customer_account): CustomerAccountResource
    {
        $customer_account->update($request->validated());

        return new CustomerAccountResource($customer_account);
    }

    public function destroy(CustomerAccount $customer_account): JsonResponse
    {
        $customer_account->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<CustomerAccount>  $query
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

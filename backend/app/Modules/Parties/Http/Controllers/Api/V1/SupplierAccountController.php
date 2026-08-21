<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Http\Requests\SupplierAccount\IndexSupplierAccountRequest;
use App\Modules\Parties\Http\Requests\SupplierAccount\StoreSupplierAccountRequest;
use App\Modules\Parties\Http\Requests\SupplierAccount\UpdateSupplierAccountRequest;
use App\Modules\Parties\Http\Resources\SupplierAccountResource;
use App\Modules\Parties\Models\SupplierAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierAccountController extends Controller
{
    /** @var array<string, string> */
    private const INCLUDABLE = [
        'party' => 'party',
    ];

    public function index(IndexSupplierAccountRequest $request): AnonymousResourceCollection
    {
        $query = SupplierAccount::query();

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

        return SupplierAccountResource::collection($query->paginate($perPage));
    }

    public function store(StoreSupplierAccountRequest $request): JsonResponse
    {
        $account = SupplierAccount::create($request->validated());

        // status/custom_fields fall back to a Postgres column default when
        // omitted — see ItemController::store() for the same fix and why.
        $account->refresh();

        return (new SupplierAccountResource($account))->response()->setStatusCode(201);
    }

    public function show(Request $request, SupplierAccount $supplier_account): SupplierAccountResource
    {
        return new SupplierAccountResource($supplier_account->load($this->requestedIncludes($request)));
    }

    public function update(UpdateSupplierAccountRequest $request, SupplierAccount $supplier_account): SupplierAccountResource
    {
        $supplier_account->update($request->validated());

        return new SupplierAccountResource($supplier_account);
    }

    public function destroy(SupplierAccount $supplier_account): JsonResponse
    {
        $supplier_account->delete();

        return response()->json(status: 204);
    }

    /**
     * @param  Builder<SupplierAccount>  $query
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

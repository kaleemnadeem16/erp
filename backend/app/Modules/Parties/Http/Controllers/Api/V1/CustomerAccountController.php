<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Actions\ArchiveCustomerAccount;
use App\Modules\Parties\Actions\CreateCustomerAccount;
use App\Modules\Parties\Actions\UpdateCustomerAccount;
use App\Modules\Parties\Http\Requests\CustomerAccount\IndexCustomerAccountRequest;
use App\Modules\Parties\Http\Requests\CustomerAccount\ShowCustomerAccountRequest;
use App\Modules\Parties\Http\Requests\CustomerAccount\StoreCustomerAccountRequest;
use App\Modules\Parties\Http\Requests\CustomerAccount\UpdateCustomerAccountRequest;
use App\Modules\Parties\Http\Resources\CustomerAccountResource;
use App\Modules\Parties\Models\CustomerAccount;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class CustomerAccountController extends Controller
{
    use AppliesApiQuery;

    /** @var array<string, string> */
    private const INCLUDABLE = [
        'party' => 'party',
    ];

    /** @var array<string, string> */
    private const SORTABLE = [
        'code' => 'code',
        'created_at' => 'created_at',
    ];

    public function index(IndexCustomerAccountRequest $request): AnonymousResourceCollection
    {
        $query = CustomerAccount::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        if ($request->filled('filter.party_id')) {
            $query->where('party_id', $request->input('filter.party_id'));
        }
        if ($request->filled('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }
        if ($request->filled('filter.search')) {
            $query->where('code', 'ilike', '%'.$request->string('filter.search')->toString().'%');
        }
        $this->applySort($query, $request, self::SORTABLE, 'code');

        return CustomerAccountResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreCustomerAccountRequest $request, CreateCustomerAccount $create): JsonResponse
    {
        $customer_account = $create($request->validated());

        return (new CustomerAccountResource($customer_account))->response()->setStatusCode(201);
    }

    public function show(ShowCustomerAccountRequest $request, CustomerAccount $customer_account): CustomerAccountResource
    {
        return new CustomerAccountResource($customer_account->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateCustomerAccountRequest $request, CustomerAccount $customer_account, UpdateCustomerAccount $update): CustomerAccountResource
    {
        return new CustomerAccountResource($update($customer_account, $request->validated()));
    }

    public function destroy(CustomerAccount $customer_account, ArchiveCustomerAccount $archive): Response
    {
        $archive($customer_account);

        return response()->noContent();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Actions\ArchiveSupplierAccount;
use App\Modules\Parties\Actions\CreateSupplierAccount;
use App\Modules\Parties\Actions\UpdateSupplierAccount;
use App\Modules\Parties\Http\Requests\SupplierAccount\IndexSupplierAccountRequest;
use App\Modules\Parties\Http\Requests\SupplierAccount\ShowSupplierAccountRequest;
use App\Modules\Parties\Http\Requests\SupplierAccount\StoreSupplierAccountRequest;
use App\Modules\Parties\Http\Requests\SupplierAccount\UpdateSupplierAccountRequest;
use App\Modules\Parties\Http\Resources\SupplierAccountResource;
use App\Modules\Parties\Models\SupplierAccount;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class SupplierAccountController extends Controller
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

    public function index(IndexSupplierAccountRequest $request): AnonymousResourceCollection
    {
        $query = SupplierAccount::query();
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

        return SupplierAccountResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreSupplierAccountRequest $request, CreateSupplierAccount $create): JsonResponse
    {
        $supplier_account = $create($request->validated(), $request->user());

        return (new SupplierAccountResource($supplier_account))->response()->setStatusCode(201);
    }

    public function show(ShowSupplierAccountRequest $request, SupplierAccount $supplier_account): SupplierAccountResource
    {
        return new SupplierAccountResource($supplier_account->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateSupplierAccountRequest $request, SupplierAccount $supplier_account, UpdateSupplierAccount $update): SupplierAccountResource
    {
        return new SupplierAccountResource($update($supplier_account, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, SupplierAccount $supplier_account, ArchiveSupplierAccount $archive): Response
    {
        $archive($supplier_account, $request->user());

        return response()->noContent();
    }
}

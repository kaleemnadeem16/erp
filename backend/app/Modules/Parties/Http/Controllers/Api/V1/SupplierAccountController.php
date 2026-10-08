<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Support\AccountMappingAccess;
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
        'payment_terms_days' => 'payment_terms_days',
        'lead_time_days' => 'lead_time_days',
        'status' => 'status',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
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
        if ($request->filled('filter.default_currency_id')) {
            $query->where('default_currency_id', $request->input('filter.default_currency_id'));
        }
        if ($request->filled('filter.payable_account_id') && AccountMappingAccess::canView($request->user())) {
            $query->where('payable_account_id', $request->input('filter.payable_account_id'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(function ($q) use ($search) {
                $q->where('code', 'ilike', '%'.$search.'%')
                    ->orWhereHas('party', fn ($pq) => $pq
                        ->where('name', 'ilike', '%'.$search.'%')
                        ->orWhere('code', 'ilike', '%'.$search.'%')
                        ->orWhere('tax_id', 'ilike', '%'.$search.'%')
                        ->orWhere('registration_no', 'ilike', '%'.$search.'%')
                    );
            });
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
        abort_unless($request->user()?->can('parties.supplier-account.manage'), 403);
        $archive($supplier_account, $request->user());

        return response()->noContent();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\ArchiveItemSupplier;
use App\Modules\Purchasing\Actions\CreateItemSupplier;
use App\Modules\Purchasing\Actions\UpdateItemSupplier;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\IndexItemSupplierRequest;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\ShowItemSupplierRequest;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\StoreItemSupplierRequest;
use App\Modules\Purchasing\Http\Requests\ItemSupplier\UpdateItemSupplierRequest;
use App\Modules\Purchasing\Http\Resources\ItemSupplierResource;
use App\Modules\Purchasing\Models\ItemSupplier;
use App\Support\Concerns\AppliesApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ItemSupplierController extends Controller
{
    use AppliesApiQuery;

    private const INCLUDABLE = ['item' => 'item.category', 'supplierAccount' => 'supplierAccount.party', 'purchaseUom' => 'purchaseUom'];

    private const SORTABLE = [
        'priority' => 'priority',
        'supplier_item_code' => 'supplier_item_code',
        'lead_time_days' => 'lead_time_days',
        'minimum_order_qty' => 'minimum_order_qty',
        'active' => 'active',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public function index(IndexItemSupplierRequest $request): AnonymousResourceCollection
    {
        $query = ItemSupplier::query();
        $this->applyIncludes($query, $request, self::INCLUDABLE);
        foreach (['item_id', 'supplier_account_id', 'active'] as $field) {
            if ($request->filled("filter.{$field}")) {
                $query->where($field, $request->input("filter.{$field}"));
            }
        }
        if ($request->boolean('filter.preferred')) {
            $query->where('priority', 1)->where('active', true);
        }
        if ($request->filled('filter.supplier_item_code')) {
            $query->where('supplier_item_code', 'ilike', '%'.$request->string('filter.supplier_item_code')->toString().'%');
        }
        if ($request->filled('filter.lead_time_min')) {
            $query->where('lead_time_days', '>=', $request->integer('filter.lead_time_min'));
        }
        if ($request->filled('filter.lead_time_max')) {
            $query->where('lead_time_days', '<=', $request->integer('filter.lead_time_max'));
        }
        if ($request->filled('filter.moq_min')) {
            $query->where('minimum_order_qty', '>=', $request->input('filter.moq_min'));
        }
        if ($request->filled('filter.moq_max')) {
            $query->where('minimum_order_qty', '<=', $request->input('filter.moq_max'));
        }
        if ($request->filled('filter.search')) {
            $search = $request->string('filter.search')->toString();
            $query->where(fn (Builder $q) => $q
                ->where('supplier_item_code', 'ilike', "%{$search}%")
                ->orWhereHas('item', fn (Builder $iq) => $iq->where('code', 'ilike', "%{$search}%")
                    ->orWhere('name', 'ilike', "%{$search}%"))
                ->orWhereHas('supplierAccount', fn (Builder $sq) => $sq
                    ->where('code', 'ilike', "%{$search}%")
                    ->orWhereHas('party', fn (Builder $pq) => $pq->where('name', 'ilike', "%{$search}%"))));
        }
        $this->applySort($query, $request, self::SORTABLE, 'priority');

        return ItemSupplierResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreItemSupplierRequest $request, CreateItemSupplier $create): JsonResponse
    {
        return (new ItemSupplierResource($create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(ShowItemSupplierRequest $request, ItemSupplier $item_supplier): ItemSupplierResource
    {
        return new ItemSupplierResource($item_supplier->load($this->requestedIncludes($request, self::INCLUDABLE)));
    }

    public function update(UpdateItemSupplierRequest $request, ItemSupplier $item_supplier, UpdateItemSupplier $update): ItemSupplierResource
    {
        return new ItemSupplierResource($update($item_supplier, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, ItemSupplier $item_supplier, ArchiveItemSupplier $archive): Response
    {
        abort_unless($request->user()?->can('purchasing.item-supplier.manage'), 403);
        $archive($item_supplier, $request->user());

        return response()->noContent();
    }
}

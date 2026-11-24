<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Administration\Support\MemberStatus;
use App\Modules\Purchasing\Http\Requests\PurchaseOrder\PurchaseOrderBuyerLookupRequest;
use App\Modules\Purchasing\Http\Resources\PurchaseOrderBuyerResource;
use App\Support\CompanyContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderBuyerController extends Controller
{
    /** Active members of this company who may be named buyer on a purchase order, found by name. Only id and name leave. */
    public function index(PurchaseOrderBuyerLookupRequest $request): AnonymousResourceCollection
    {
        $query = User::query()
            ->where('company_id', app(CompanyContext::class)->id())
            ->where('status', MemberStatus::Active->value)
            ->orderBy('name')->orderBy('id');
        if ($request->filled('search')) {
            $term = '%'.addcslashes($request->string('search')->toString(), '%_\\').'%';
            $query->where('name', 'ilike', $term);
        }

        return PurchaseOrderBuyerResource::collection($query->select(['id', 'name'])->paginate($request->integer('per_page', 25)));
    }
}

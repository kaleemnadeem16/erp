<?php

declare(strict_types=1);

namespace App\Modules\Parties\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Parties\Actions\CreateSupplierSite;
use App\Modules\Parties\Actions\DeleteSupplierSite;
use App\Modules\Parties\Actions\UpdateSupplierSite;
use App\Modules\Parties\Http\Requests\SupplierSite\IndexSupplierSiteRequest;
use App\Modules\Parties\Http\Requests\SupplierSite\StoreSupplierSiteRequest;
use App\Modules\Parties\Http\Requests\SupplierSite\UpdateSupplierSiteRequest;
use App\Modules\Parties\Http\Resources\SupplierSiteResource;
use App\Modules\Parties\Models\SupplierAccount;
use App\Modules\Parties\Models\SupplierSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nested under its parent for index/store (a site only ever makes sense as
 * "this supplier's site"), promoted to a flat route for update/destroy once
 * the caller has the site's own id — same shape as QualityDispositionController.
 */
class SupplierSiteController extends Controller
{
    public function index(IndexSupplierSiteRequest $request, SupplierAccount $supplier_account): AnonymousResourceCollection
    {
        $query = $supplier_account->sites()->with('partySite')->orderByDesc('is_default');
        if ($request->filled('filter.role')) {
            $query->where('role', $request->input('filter.role'));
        }

        return SupplierSiteResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreSupplierSiteRequest $request, SupplierAccount $supplier_account, CreateSupplierSite $create): JsonResponse
    {
        $site = $create($supplier_account, $request->validated(), $request->user());

        return (new SupplierSiteResource($site))->response()->setStatusCode(201);
    }

    public function update(UpdateSupplierSiteRequest $request, SupplierSite $supplier_site, UpdateSupplierSite $update): SupplierSiteResource
    {
        return new SupplierSiteResource($update($supplier_site, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, SupplierSite $supplier_site, DeleteSupplierSite $delete): Response
    {
        abort_unless($request->user()?->can('parties.supplier-site.manage'), 403);
        $delete($supplier_site, $request->user());

        return response()->noContent();
    }
}

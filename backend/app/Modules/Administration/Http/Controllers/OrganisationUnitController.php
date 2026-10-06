<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Actions\DeleteOrganisationUnit;
use App\Modules\Administration\Actions\SaveOrganisationUnit;
use App\Modules\Administration\Actions\SyncOrganisationUnitMembers;
use App\Modules\Administration\Actions\SyncOrganisationUnitResources;
use App\Modules\Administration\Http\Requests\IndexOrganisationUnitRequest;
use App\Modules\Administration\Http\Requests\StoreOrganisationUnitRequest;
use App\Modules\Administration\Http\Requests\SyncOrganisationUnitMembersRequest;
use App\Modules\Administration\Http\Requests\SyncOrganisationUnitResourcesRequest;
use App\Modules\Administration\Http\Requests\UpdateOrganisationUnitRequest;
use App\Modules\Administration\Http\Resources\OrganisationUnitResource;
use App\Modules\Administration\Models\OrganisationUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The company's own organisation tree, who belongs to each unit and which resources each owns (ADR 0089, Q26). A flat list with
 * `parent_id`; the screen builds the tree. Company scoping is the model's own, so another company's unit is a 404.
 */
class OrganisationUnitController extends Controller
{
    public function index(IndexOrganisationUnitRequest $request): AnonymousResourceCollection
    {
        return OrganisationUnitResource::collection(OrganisationUnit::query()->with(['members:id,name,email', 'resources'])->orderBy('name')->get());
    }

    public function show(IndexOrganisationUnitRequest $request, OrganisationUnit $organisationUnit): OrganisationUnitResource
    {
        return new OrganisationUnitResource($organisationUnit);
    }

    public function store(StoreOrganisationUnitRequest $request, SaveOrganisationUnit $save): JsonResponse
    {
        return (new OrganisationUnitResource($save(null, $request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function update(UpdateOrganisationUnitRequest $request, OrganisationUnit $organisationUnit, SaveOrganisationUnit $save): OrganisationUnitResource
    {
        return new OrganisationUnitResource($save($organisationUnit, $request->validated(), $request->user()));
    }

    public function destroy(UpdateOrganisationUnitRequest $request, OrganisationUnit $organisationUnit, DeleteOrganisationUnit $delete): JsonResponse
    {
        $delete($organisationUnit, $request->user());

        return response()->json(status: 204);
    }

    public function members(SyncOrganisationUnitMembersRequest $request, OrganisationUnit $organisationUnit, SyncOrganisationUnitMembers $sync): OrganisationUnitResource
    {
        return new OrganisationUnitResource($sync($organisationUnit, $request->validated('user_ids'), $request->user()));
    }

    public function resources(SyncOrganisationUnitResourcesRequest $request, OrganisationUnit $organisationUnit, SyncOrganisationUnitResources $sync): OrganisationUnitResource
    {
        return new OrganisationUnitResource($sync($organisationUnit, $request->validated('resources'), $request->user()));
    }
}

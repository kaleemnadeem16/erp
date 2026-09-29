<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Modules\Administration\Actions\DeleteRole;
use App\Modules\Administration\Actions\SaveRole;
use App\Modules\Administration\Http\Requests\StoreRoleRequest;
use App\Modules\Administration\Http\Resources\RoleResource;
use App\Support\CompanyContext;
use App\Support\PermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    public function index(Request $request, CompanyContext $company)
    {
        abort_unless($request->user()?->can('administration.role.view'), 403);

        return RoleResource::collection(Role::query()->where('team_id', $company->id())->with('permissions')->withCount('users')->orderBy('name')->get());
    }

    public function store(StoreRoleRequest $request, SaveRole $save): RoleResource
    {
        return new RoleResource($save(null, $request->validated(), $request->user()));
    }

    public function show(Request $request, int $role, CompanyContext $company): RoleResource
    {
        abort_unless($request->user()?->can('administration.role.view'), 403);

        return new RoleResource($this->role($role, $company)->load('permissions')->loadCount('users'));
    }

    public function update(StoreRoleRequest $request, int $role, CompanyContext $company, SaveRole $save): RoleResource
    {
        return new RoleResource($save($this->role($role, $company), $request->validated(), $request->user()));
    }

    public function destroy(Request $request, int $role, CompanyContext $company, DeleteRole $delete): JsonResponse
    {
        abort_unless($request->user()?->can('administration.role.manage'), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $delete($this->role($role, $company), $data['reason'], $request->user());

        return response()->json(status: 204);
    }

    public function permissions(Request $request, PermissionRegistry $registry): JsonResponse
    {
        abort_unless($request->user()?->can('administration.role.view'), 403);

        return response()->json(['data' => $registry->byOwner()]);
    }

    private function role(int $id, CompanyContext $company): Role
    {
        return Role::query()->where('team_id', $company->id())->findOrFail($id);
    }
}

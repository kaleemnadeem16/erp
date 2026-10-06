<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Models\User;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Modules\Administration\Models\OrganisationUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $member */ $member = $this->resource;
        $mayAdminister = $request->user()?->canAny(['administration.access.assign', 'administration.organisation.view', 'administration.organisation.manage']) === true;

        return [
            'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
            'status' => $member->status->value, 'invited_at' => $member->invited_at,
            'suspended_at' => $member->suspended_at,
            'created_at' => $member->created_at,
            'updated_at' => $member->updated_at,
            'last_login_at' => $member->last_login_at,
            'access_updated_at' => $member->access_updated_at,
            'roles' => $member->roles->map(fn ($role) => ['id' => $role->getKey(), 'name' => $role->getAttribute('name')])->values(),
            'direct_permissions' => $member->getDirectPermissions()->pluck('name')->sort()->values(),
            'effective_permissions' => $member->getAllPermissions()->pluck('name')->sort()->values(),
            // Where the person works and which of their assignments are limited to it (ADR 0089); shown to those who administer access.
            'organisation_units' => $this->when($mayAdminister, fn () => OrganisationUnit::query()->whereHas('members', fn ($q) => $q->where('users.id', $member->id))->orderBy('name')->get(['id', 'code', 'name'])->map(fn ($u): array => ['id' => $u->id, 'code' => $u->code, 'name' => $u->name])->values()),
            'scope_limits' => $this->when($mayAdminister, fn () => AccessScopeLimit::query()->where('user_id', $member->id)->orderBy('id')->get()->map(fn ($l): array => ['role_id' => $l->role_id, 'permission' => $l->permission, 'scope' => $l->scope])->values()),
        ];
    }
}

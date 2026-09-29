<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var User $member */ $member = $this->resource;

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
        ];
    }
}

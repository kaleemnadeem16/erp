<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $member */ $member = $this->resource;

        return [
            'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
            'status' => $member->status->value, 'invited_at' => $member->invited_at,
            'suspended_at' => $member->suspended_at,
            'roles' => $member->roles->map(fn ($role) => ['id' => $role->id, 'name' => $role->name])->values(),
            'direct_permissions' => $member->getDirectPermissions()->pluck('name')->sort()->values(),
            'effective_permissions' => $member->getAllPermissions()->pluck('name')->sort()->values(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return ['id' => $role->id, 'name' => $role->name, 'description' => $role->description, 'permissions' => $role->permissions->pluck('name')->sort()->values(), 'members_count' => $this->whenCounted('users')];
    }
}

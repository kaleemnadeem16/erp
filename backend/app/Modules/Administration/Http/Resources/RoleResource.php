<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'permissions' => $this->permissions->pluck('name')->sort()->values(), 'members_count' => $this->whenCounted('users')];
    }
}

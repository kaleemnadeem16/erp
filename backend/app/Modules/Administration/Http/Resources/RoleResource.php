<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Models\Role;
use App\Modules\Administration\Models\AccessScopeLimit;
use App\Support\Scope\ResourceScopeAdapters;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Role $role */
        $role = $this->resource;

        return ['id' => $role->id, 'name' => $role->name, 'description' => $role->description, 'permissions' => $role->permissions->pluck('name')->sort()->values(), 'members_count' => $this->whenCounted('users'),
            // The ways this role can be limited when given to someone: "only my units" always, and "own" / "assigned" only where EVERY duty of the role supports them (a mixed role fails closed).
            'limit_modes' => $this->limitModes($role)];
    }

    /** @return list<string> */
    private function limitModes(Role $role): array
    {
        $duties = $role->permissions->pluck('name')->all();
        $modes = [AccessScopeLimit::MY_UNITS];
        foreach ([AccessScopeLimit::OWN, AccessScopeLimit::ASSIGNED] as $scope) {
            if ($duties !== [] && collect($duties)->every(fn (string $duty): bool => app(ResourceScopeAdapters::class)->supportsAssignment($duty, $scope))) {
                $modes[] = $scope;
            }
        }

        return $modes;
    }
}

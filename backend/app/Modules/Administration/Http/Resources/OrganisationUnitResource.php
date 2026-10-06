<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Resources;

use App\Modules\Administration\Models\OrganisationUnit;
use App\Support\Scope\ScopedResourceTypes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganisationUnit */
class OrganisationUnitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OrganisationUnit $unit */
        $unit = $this->resource;
        $types = app(ScopedResourceTypes::class);
        $unit->loadMissing(['members:id,name,email', 'resources']);

        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'name' => $unit->name,
            'parent_id' => $unit->parent_id,
            'status' => $unit->status,
            'members' => $unit->members->map(fn ($m): array => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email])->values()->all(),
            'resources' => $unit->resources->map(fn ($r): array => [
                'type' => $r->resource_type, 'type_label' => $types->label($r->resource_type), 'id' => $r->resource_id, 'name' => $types->name($r->resource_type, $r->resource_id),
            ])->values()->all(),
        ];
    }
}

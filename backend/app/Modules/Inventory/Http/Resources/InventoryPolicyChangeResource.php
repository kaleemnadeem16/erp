<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Http\Resources\UserResource;
use App\Modules\Inventory\Models\InventoryPolicyChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InventoryPolicyChange */
class InventoryPolicyChangeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InventoryPolicyChange $change */
        $change = $this->resource;

        return [
            'id' => $change->id,
            'policy' => $change->policy,
            'old_values' => $change->old_values,
            'new_values' => $change->new_values,
            'reason' => $change->reason,
            'changed_by' => $change->changed_by,
            'changed_by_user' => $this->whenLoaded('changedBy', fn () => new UserResource($change->changedBy)),
            'changed_at' => $change->changed_at->toIso8601String(),
        ];
    }
}

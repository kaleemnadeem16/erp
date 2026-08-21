<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Http\Resources;

use App\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChartOfAccount
 */
class ChartOfAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChartOfAccount $account */
        $account = $this->resource;

        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'parent_id' => $account->parent_id,
            'is_postable' => $account->is_postable,
            'currency_id' => $account->currency_id,
            'status' => $account->status->value,
            'parent' => $this->whenLoaded('parent', fn () => new ChartOfAccountResource($account->parent)),
            'children' => $this->whenLoaded('children', fn () => ChartOfAccountResource::collection($account->children)),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}

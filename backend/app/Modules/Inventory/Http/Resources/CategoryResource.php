<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Modules\Accounting\Support\AccountMappingAccess;
use App\Modules\Inventory\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Category $category */
        $category = $this->resource;

        // whenLoaded() must wrap the resource, not the reverse, or Scramble's
        // OpenAPI inference can't see the field is conditional and types it as
        // always-present in the generated schema — see ExchangeRateResource.
        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'code' => $category->code,
            'name' => $category->name,
            'income_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $category->income_account_id),
            'expense_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $category->expense_account_id),
            'inventory_account_id' => $this->when(AccountMappingAccess::canView($request->user()), fn () => $category->inventory_account_id),
            'default_valuation_method' => $category->default_valuation_method?->value,
            'parent' => $this->whenLoaded('parent', fn () => new CategoryResource($category->parent)),
            'children' => $this->whenLoaded('children', fn () => CategoryResource::collection($category->children)),
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }
}

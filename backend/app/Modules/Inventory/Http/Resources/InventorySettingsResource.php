<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Resources;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class InventorySettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Company $company */
        $company = $this->resource;

        return [
            'inventory_location_mode' => $company->inventory_location_mode->value,
            'default_avco_pool_scope' => $company->default_avco_pool_scope->value,
        ];
    }
}

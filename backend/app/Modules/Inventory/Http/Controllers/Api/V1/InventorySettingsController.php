<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Inventory\Actions\UpdateInventorySettings;
use App\Modules\Inventory\Http\Requests\InventorySettings\UpdateInventorySettingsRequest;
use App\Modules\Inventory\Http\Resources\InventorySettingsResource;
use App\Support\CompanyContext;

class InventorySettingsController extends Controller
{
    public function show(CompanyContext $companyContext): InventorySettingsResource
    {
        return new InventorySettingsResource(Company::query()->findOrFail($companyContext->id()));
    }

    public function update(UpdateInventorySettingsRequest $request, UpdateInventorySettings $update): InventorySettingsResource
    {
        $attributes = $request->safe()->except('reason');

        return new InventorySettingsResource($update(
            $attributes,
            $request->user(),
            $request->validated('reason'),
        ));
    }
}

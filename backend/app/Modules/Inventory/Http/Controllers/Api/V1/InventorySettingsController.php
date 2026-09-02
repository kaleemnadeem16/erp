<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Inventory\Actions\UpdateInventorySettings;
use App\Modules\Inventory\Http\Requests\InventorySettings\UpdateInventorySettingsRequest;
use App\Modules\Inventory\Http\Resources\InventorySettingsResource;
use App\Modules\Inventory\Support\InventoryLocationMode;
use App\Support\CompanyContext;

class InventorySettingsController extends Controller
{
    public function show(CompanyContext $companyContext): InventorySettingsResource
    {
        return new InventorySettingsResource(Company::query()->findOrFail($companyContext->id()));
    }

    public function update(UpdateInventorySettingsRequest $request, UpdateInventorySettings $update): InventorySettingsResource
    {
        $mode = InventoryLocationMode::from($request->string('inventory_location_mode')->toString());

        return new InventorySettingsResource($update($mode));
    }
}

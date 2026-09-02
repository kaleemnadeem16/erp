<?php

namespace App\Modules\Purchasing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Purchasing\Actions\ResolvePurchasingSettings;
use App\Modules\Purchasing\Actions\UpdatePurchasingSettings;
use App\Modules\Purchasing\Http\Requests\PurchasingSettings\UpdatePurchasingSettingsRequest;
use App\Modules\Purchasing\Http\Resources\PurchasingSettingsResource;

class PurchasingSettingsController extends Controller
{
    public function show(ResolvePurchasingSettings $resolve): PurchasingSettingsResource
    {
        return new PurchasingSettingsResource($resolve());
    }

    public function update(UpdatePurchasingSettingsRequest $request, UpdatePurchasingSettings $update): PurchasingSettingsResource
    {
        return new PurchasingSettingsResource($update($request->validated()));
    }
}

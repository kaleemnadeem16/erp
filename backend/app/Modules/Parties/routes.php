<?php

use App\Modules\Parties\Http\Controllers\Api\V1\CustomerAccountController;
use App\Modules\Parties\Http\Controllers\Api\V1\PartyController;
use App\Modules\Parties\Http\Controllers\Api\V1\SupplierAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('parties', PartyController::class);
    Route::apiResource('customer-accounts', CustomerAccountController::class);
    Route::apiResource('supplier-accounts', SupplierAccountController::class);
});

<?php

use App\Modules\Inventory\Http\Controllers\Api\V1\CategoryController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('items', ItemController::class);
});

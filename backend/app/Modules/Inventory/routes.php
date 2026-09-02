<?php

use App\Modules\Inventory\Http\Controllers\Api\V1\CategoryController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventorySettingsController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemController;
use App\Modules\Inventory\Http\Controllers\Api\V1\LocationController;
use App\Modules\Inventory\Http\Controllers\Api\V1\WarehouseController;
use App\Modules\Inventory\Http\Controllers\Api\V1\WarehouseLocationAssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('categories', CategoryController::class)->except('update');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::apiResource('items', ItemController::class)->except('update');
    Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::apiResource('warehouses', WarehouseController::class)->except('update');
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::apiResource('locations', LocationController::class)->except('update');
    Route::patch('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::get('inventory-settings', [InventorySettingsController::class, 'show'])->name('inventory-settings.show');
    Route::patch('inventory-settings', [InventorySettingsController::class, 'update'])->name('inventory-settings.update');
    Route::apiResource('warehouse-location-roles', WarehouseLocationAssignmentController::class)
        ->parameters(['warehouse-location-roles' => 'warehouseLocationRole'])->except('update');
    Route::patch('warehouse-location-roles/{warehouseLocationRole}', [WarehouseLocationAssignmentController::class, 'update'])
        ->name('warehouse-location-roles.update');
});

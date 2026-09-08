<?php

use App\Modules\Inventory\Http\Controllers\Api\V1\CategoryController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventoryCutoverController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventorySettingsController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemController;
use App\Modules\Inventory\Http\Controllers\Api\V1\LocationController;
use App\Modules\Inventory\Http\Controllers\Api\V1\PhysicalCountController;
use App\Modules\Inventory\Http\Controllers\Api\V1\StockMoveController;
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
    Route::apiResource('stock-moves', StockMoveController::class)
        ->parameters(['stock-moves' => 'stockMove'])->only(['index', 'show']);
    Route::apiResource('physical-counts', PhysicalCountController::class)->only(['index', 'store', 'show']);
    Route::post('physical-counts/{physicalCount}/lines', [PhysicalCountController::class, 'storeLine'])->name('physical-counts.lines.store');
    Route::post('physical-count-lines/{physicalCountLine}/count', [PhysicalCountController::class, 'count'])->name('physical-count-lines.count');
    Route::post('physical-counts/{physicalCount}/post', [PhysicalCountController::class, 'post'])->name('physical-counts.post');
    Route::post('physical-counts/{physicalCount}/cancel', [PhysicalCountController::class, 'cancel'])->name('physical-counts.cancel');
    Route::apiResource('inventory-cutovers', InventoryCutoverController::class)->only(['index', 'store', 'show']);
    Route::post('inventory-cutovers/{inventoryCutover}/batches', [InventoryCutoverController::class, 'storeBatch'])->name('inventory-cutovers.batches.store');
    Route::post('inventory-cutovers/{inventoryCutover}/validate', [InventoryCutoverController::class, 'validateCutover'])->name('inventory-cutovers.validate');
    Route::get('inventory-cutovers/{inventoryCutover}/preview', [InventoryCutoverController::class, 'preview'])->name('inventory-cutovers.preview');
    Route::post('inventory-cutovers/{inventoryCutover}/approve', [InventoryCutoverController::class, 'approve'])->name('inventory-cutovers.approve');
    Route::post('inventory-cutovers/{inventoryCutover}/commit', [InventoryCutoverController::class, 'commit'])->name('inventory-cutovers.commit');
    Route::post('inventory-cutovers/{inventoryCutover}/finalise', [InventoryCutoverController::class, 'finalise'])->name('inventory-cutovers.finalise');
    Route::post('inventory-cutovers/{inventoryCutover}/negative-openings', [InventoryCutoverController::class, 'requestNegativeOpening'])->name('inventory-cutovers.negative-openings.store');
    Route::post('inventory-cutover-negative-openings/{inventoryCutoverNegativeOpening}/approve', [InventoryCutoverController::class, 'approveNegativeOpening'])->name('inventory-cutover-negative-openings.approve');
    Route::get('inventory-settings', [InventorySettingsController::class, 'show'])->name('inventory-settings.show');
    Route::patch('inventory-settings', [InventorySettingsController::class, 'update'])->name('inventory-settings.update');
    Route::apiResource('warehouse-location-roles', WarehouseLocationAssignmentController::class)
        ->parameters(['warehouse-location-roles' => 'warehouseLocationRole'])->except('update');
    Route::patch('warehouse-location-roles/{warehouseLocationRole}', [WarehouseLocationAssignmentController::class, 'update'])
        ->name('warehouse-location-roles.update');
});

<?php

use App\Modules\Inventory\Http\Controllers\Api\V1\AvcoPoolController;
use App\Modules\Inventory\Http\Controllers\Api\V1\CategoryController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventoryCutoverController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventoryOverviewController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventoryPolicyChangeController;
use App\Modules\Inventory\Http\Controllers\Api\V1\InventorySettingsController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemPackageTrackingController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemPackagingController;
use App\Modules\Inventory\Http\Controllers\Api\V1\ItemQuantityUnitController;
use App\Modules\Inventory\Http\Controllers\Api\V1\LocationController;
use App\Modules\Inventory\Http\Controllers\Api\V1\NegativeStockOverrideRequestController;
use App\Modules\Inventory\Http\Controllers\Api\V1\PackageBalanceController;
use App\Modules\Inventory\Http\Controllers\Api\V1\PackageCheckpointController;
use App\Modules\Inventory\Http\Controllers\Api\V1\PackageDrawController;
use App\Modules\Inventory\Http\Controllers\Api\V1\PhysicalCountController;
use App\Modules\Inventory\Http\Controllers\Api\V1\RecalculationRequestController;
use App\Modules\Inventory\Http\Controllers\Api\V1\StockIssueController;
use App\Modules\Inventory\Http\Controllers\Api\V1\StockLayerController;
use App\Modules\Inventory\Http\Controllers\Api\V1\StockMoveController;
use App\Modules\Inventory\Http\Controllers\Api\V1\StockTransferController;
use App\Modules\Inventory\Http\Controllers\Api\V1\WarehouseController;
use App\Modules\Inventory\Http\Controllers\Api\V1\WarehouseLocationAssignmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('package-balances', [PackageBalanceController::class, 'index'])->name('package-balances.index');
    Route::post('package-draws/suggest', [PackageDrawController::class, 'suggest'])->name('package-draws.suggest');
    Route::post('package-checkpoints', [PackageCheckpointController::class, 'store'])->name('package-checkpoints.store');
    Route::post('stock-issues', [StockIssueController::class, 'store'])->name('stock-issues.store');
    Route::post('stock-transfers', [StockTransferController::class, 'store'])->name('stock-transfers.store');
    Route::get('items/{item}/package-tracking', [ItemPackageTrackingController::class, 'show'])->name('items.package-tracking.show');
    Route::post('items/{item}/package-tracking/preview', [ItemPackageTrackingController::class, 'preview'])->name('items.package-tracking.preview');
    Route::post('items/{item}/package-tracking/activate', [ItemPackageTrackingController::class, 'activate'])->name('items.package-tracking.activate');
    Route::get('item-packagings', [ItemPackagingController::class, 'index'])->name('item-packagings.index');
    Route::post('item-packagings', [ItemPackagingController::class, 'store'])->name('item-packagings.store');
    Route::patch('item-packagings/{item_packaging}', [ItemPackagingController::class, 'update'])->name('item-packagings.update');
    Route::apiResource('categories', CategoryController::class)->except('update');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::apiResource('items', ItemController::class)->except('update');
    Route::patch('items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::apiResource('item-quantity-units', ItemQuantityUnitController::class)->except('update');
    Route::patch('item-quantity-units/{item_quantity_unit}', [ItemQuantityUnitController::class, 'update'])->name('item-quantity-units.update');
    Route::apiResource('warehouses', WarehouseController::class)->except('update');
    Route::patch('warehouses/{warehouse}', [WarehouseController::class, 'update'])->name('warehouses.update');
    Route::apiResource('locations', LocationController::class)->except('update');
    Route::patch('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
    Route::apiResource('stock-moves', StockMoveController::class)
        ->parameters(['stock-moves' => 'stockMove'])->only(['index', 'show']);
    Route::apiResource('stock-layers', StockLayerController::class)
        ->parameters(['stock-layers' => 'stockLayer'])->only(['index', 'show']);
    Route::apiResource('avco-pools', AvcoPoolController::class)
        ->parameters(['avco-pools' => 'avcoPool'])->only(['index', 'show']);
    Route::get('inventory-overview', [InventoryOverviewController::class, 'index'])->name('inventory-overview.index');
    Route::get('inventory-overview/summary', [InventoryOverviewController::class, 'summary'])->name('inventory-overview.summary');
    Route::apiResource('physical-counts', PhysicalCountController::class)->only(['index', 'store', 'show']);
    Route::post('physical-counts/{physicalCount}/lines', [PhysicalCountController::class, 'storeLine'])->name('physical-counts.lines.store');
    Route::post('physical-count-lines/{physicalCountLine}/count', [PhysicalCountController::class, 'count'])->name('physical-count-lines.count');
    Route::post('physical-count-lines/{physicalCountLine}/composition', [PhysicalCountController::class, 'composition'])->name('physical-count-lines.composition');
    Route::post('physical-count-lines/{physicalCountLine}/refresh', [PhysicalCountController::class, 'refresh'])->name('physical-count-lines.refresh');
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
    Route::get('inventory-policy-changes', [InventoryPolicyChangeController::class, 'index'])->name('inventory-policy-changes.index');
    Route::apiResource('negative-stock-override-requests', NegativeStockOverrideRequestController::class)->only(['index', 'store', 'show']);
    Route::post('negative-stock-override-requests/{negativeStockOverrideRequest}/decide', [NegativeStockOverrideRequestController::class, 'decide'])->name('negative-stock-override-requests.decide');
    Route::post('negative-stock-override-requests/{negativeStockOverrideRequest}/review', [NegativeStockOverrideRequestController::class, 'review'])->name('negative-stock-override-requests.review');
    Route::apiResource('recalculation-requests', RecalculationRequestController::class)->only(['index', 'store', 'show']);
    Route::post('recalculation-impact-versions/{recalculationImpactVersion}/decide', [RecalculationRequestController::class, 'decide'])->name('recalculation-impact-versions.decide');
    Route::post('recalculation-impact-versions/{recalculationImpactVersion}/execute', [RecalculationRequestController::class, 'execute'])->name('recalculation-impact-versions.execute');
    Route::post('recalculation-impact-versions/{recalculationImpactVersion}/resume-regeneration', [RecalculationRequestController::class, 'resumeRegeneration'])->name('recalculation-impact-versions.resume-regeneration');
    Route::apiResource('warehouse-location-roles', WarehouseLocationAssignmentController::class)
        ->parameters(['warehouse-location-roles' => 'warehouseLocationRole'])->except('update');
    Route::patch('warehouse-location-roles/{warehouseLocationRole}', [WarehouseLocationAssignmentController::class, 'update'])
        ->name('warehouse-location-roles.update');
});

<?php

use App\Modules\Purchasing\Http\Controllers\Api\V1\ItemSupplierController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchasingSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('item-suppliers', ItemSupplierController::class)->except('update');
    Route::patch('item-suppliers/{item_supplier}', [ItemSupplierController::class, 'update'])->name('item-suppliers.update');
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
    Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('purchase-order-lines/{purchaseOrderLine}/short-close', [PurchaseOrderController::class, 'shortClose'])->name('purchase-order-lines.short-close');
    Route::get('purchasing-settings', [PurchasingSettingsController::class, 'show'])->name('purchasing-settings.show');
    Route::patch('purchasing-settings', [PurchasingSettingsController::class, 'update'])->name('purchasing-settings.update');
});

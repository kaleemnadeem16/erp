<?php

use App\Modules\Purchasing\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\ItemSupplierController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchasingSettingsController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\QualityDispositionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('item-suppliers', ItemSupplierController::class)->except('update');
    Route::patch('item-suppliers/{item_supplier}', [ItemSupplierController::class, 'update'])->name('item-suppliers.update');
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
    Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('purchase-order-lines/{purchaseOrderLine}/short-close', [PurchaseOrderController::class, 'shortClose'])->name('purchase-order-lines.short-close');
    Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['store', 'show']);
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');
    Route::post('goods-receipt-lines/{goodsReceiptLine}/quality-dispositions', [QualityDispositionController::class, 'store'])->name('quality-dispositions.store');
    Route::get('goods-receipt-lines/{goodsReceiptLine}/quality-dispositions', [QualityDispositionController::class, 'index'])->name('quality-dispositions.index');
    Route::get('quality-dispositions/{qualityDisposition}', [QualityDispositionController::class, 'show'])->name('quality-dispositions.show');
    Route::get('purchasing-settings', [PurchasingSettingsController::class, 'show'])->name('purchasing-settings.show');
    Route::patch('purchasing-settings', [PurchasingSettingsController::class, 'update'])->name('purchasing-settings.update');
});

<?php

use App\Modules\Purchasing\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\GoodsReceiptLineCompositionController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\ItemSupplierController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchasingPolicyChangeController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\PurchasingSettingsController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\QualityDispositionController;
use App\Modules\Purchasing\Http\Controllers\Api\V1\SupplierOfferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('supplier-offers', [SupplierOfferController::class, 'index'])->name('supplier-offers.index');
    Route::post('supplier-offers', [SupplierOfferController::class, 'store'])->name('supplier-offers.store');
    Route::patch('supplier-offers/{supplier_offer}', [SupplierOfferController::class, 'update'])->name('supplier-offers.update');
    Route::apiResource('item-suppliers', ItemSupplierController::class)->except('update');
    Route::patch('item-suppliers/{item_supplier}', [ItemSupplierController::class, 'update'])->name('item-suppliers.update');
    Route::post('purchase-order-lines/preview', [PurchaseOrderController::class, 'previewLine'])->name('purchase-order-lines.preview');
    Route::apiResource('purchase-orders', PurchaseOrderController::class)->only(['index', 'store', 'show']);
    Route::patch('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
    Route::post('purchase-orders/{purchaseOrder}/issue', [PurchaseOrderController::class, 'issue'])->name('purchase-orders.issue');
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('purchase-order-lines/{purchaseOrderLine}/short-close', [PurchaseOrderController::class, 'shortClose'])->name('purchase-order-lines.short-close');
    Route::post('inventory-cutovers/{inventoryCutover}/commit-open-purchase-orders', [PurchaseOrderController::class, 'commitCutover'])->name('inventory-cutovers.open-purchase-orders.commit');
    Route::apiResource('goods-receipts', GoodsReceiptController::class)->only(['index', 'store', 'show']);
    Route::patch('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->name('goods-receipts.update');
    Route::put('goods-receipt-lines/{goodsReceiptLine}/package-composition', [GoodsReceiptLineCompositionController::class, 'update'])->name('goods-receipt-lines.package-composition.update');
    Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');
    Route::get('goods-receipts/{goodsReceipt}/reversal-preview', [GoodsReceiptController::class, 'reversalPreview'])->name('goods-receipts.reversal-preview');
    Route::post('goods-receipts/{goodsReceipt}/reverse', [GoodsReceiptController::class, 'reverse'])->name('goods-receipts.reverse');
    Route::post('goods-receipt-lines/{goodsReceiptLine}/quality-dispositions', [QualityDispositionController::class, 'store'])->name('quality-dispositions.store');
    Route::get('goods-receipt-lines/{goodsReceiptLine}/quality-dispositions', [QualityDispositionController::class, 'index'])->name('quality-dispositions.index');
    Route::get('quality-dispositions/{qualityDisposition}', [QualityDispositionController::class, 'show'])->name('quality-dispositions.show');
    Route::get('purchasing-settings', [PurchasingSettingsController::class, 'show'])->name('purchasing-settings.show');
    Route::patch('purchasing-settings', [PurchasingSettingsController::class, 'update'])->name('purchasing-settings.update');
    Route::get('purchasing-policy-changes', [PurchasingPolicyChangeController::class, 'index'])->name('purchasing-policy-changes.index');
});

<?php

use App\Modules\Purchasing\Http\Controllers\Api\V1\ItemSupplierController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('item-suppliers', ItemSupplierController::class)->except('update');
    Route::patch('item-suppliers/{item_supplier}', [ItemSupplierController::class, 'update'])->name('item-suppliers.update');
});

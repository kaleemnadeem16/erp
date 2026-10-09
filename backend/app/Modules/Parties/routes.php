<?php

use App\Modules\Parties\Http\Controllers\Api\V1\CustomerAccountController;
use App\Modules\Parties\Http\Controllers\Api\V1\PartyController;
use App\Modules\Parties\Http\Controllers\Api\V1\SupplierAccountController;
use App\Modules\Parties\Http\Controllers\Api\V1\SupplierSiteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('parties/candidates', [PartyController::class, 'candidates'])->name('parties.candidates');
    Route::post('parties/{party}/restore', [PartyController::class, 'restore'])->whereNumber('party')->name('parties.restore');
    Route::apiResource('parties', PartyController::class)->except('update');
    Route::patch('parties/{party}', [PartyController::class, 'update'])->name('parties.update');
    Route::apiResource('customer-accounts', CustomerAccountController::class)->except('update');
    Route::patch('customer-accounts/{customer_account}', [CustomerAccountController::class, 'update'])->name('customer-accounts.update');
    Route::apiResource('supplier-accounts', SupplierAccountController::class)->except('update');
    Route::patch('supplier-accounts/{supplier_account}', [SupplierAccountController::class, 'update'])->name('supplier-accounts.update');
    Route::get('supplier-accounts/{supplier_account}/sites', [SupplierSiteController::class, 'index'])->name('supplier-accounts.sites.index');
    Route::post('supplier-accounts/{supplier_account}/sites', [SupplierSiteController::class, 'store'])->name('supplier-accounts.sites.store');
    Route::patch('supplier-sites/{supplier_site}', [SupplierSiteController::class, 'update'])->name('supplier-sites.update');
    Route::delete('supplier-sites/{supplier_site}', [SupplierSiteController::class, 'destroy'])->name('supplier-sites.destroy');
});

<?php

use App\Modules\Company\Http\Controllers\Api\V1\CompanyBrandingController;
use App\Modules\Company\Http\Controllers\Api\V1\CompanyChangeController;
use App\Modules\Company\Http\Controllers\Api\V1\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('company', [CompanyController::class, 'show'])->name('company.show');
    Route::patch('company', [CompanyController::class, 'update'])->name('company.update');
    Route::get('company/branding', [CompanyBrandingController::class, 'show'])->name('company.branding.show');
    Route::post('company/branding', [CompanyBrandingController::class, 'update'])->name('company.branding.update');
    Route::get('company/branding/logo', [CompanyBrandingController::class, 'logo'])->name('company.branding.logo');
    Route::get('company-changes', [CompanyChangeController::class, 'index'])->name('company-changes.index');
});

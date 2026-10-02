<?php

use App\Modules\Company\Http\Controllers\Api\V1\CompanyChangeController;
use App\Modules\Company\Http\Controllers\Api\V1\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('company', [CompanyController::class, 'show'])->name('company.show');
    Route::patch('company', [CompanyController::class, 'update'])->name('company.update');
    Route::get('company-changes', [CompanyChangeController::class, 'index'])->name('company-changes.index');
});

<?php

use App\Modules\Accounting\Http\Controllers\Api\V1\ChartOfAccountController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('chart-of-accounts', ChartOfAccountController::class)->except('update');
    Route::patch('chart-of-accounts/{chart_of_account}', [ChartOfAccountController::class, 'update'])->name('chart-of-accounts.update');
});

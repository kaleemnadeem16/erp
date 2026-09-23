<?php

use App\Modules\Estimating\Http\Controllers\Api\V1\ApplicationRateController;
use App\Modules\Estimating\Http\Controllers\Api\V1\RateDimensionController;
use App\Modules\Estimating\Http\Controllers\Api\V1\RateDimensionValueController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('rate-dimensions', RateDimensionController::class)->except('update');
    Route::patch('rate-dimensions/{rate_dimension}', [RateDimensionController::class, 'update'])->name('rate-dimensions.update');
    Route::get('rate-dimensions/{rate_dimension}/values', [RateDimensionValueController::class, 'index'])->name('rate-dimensions.values.index');
    Route::post('rate-dimensions/{rate_dimension}/values', [RateDimensionValueController::class, 'store'])->name('rate-dimensions.values.store');
    Route::patch('rate-dimension-values/{rate_dimension_value}', [RateDimensionValueController::class, 'update'])->name('rate-dimension-values.update');
    Route::delete('rate-dimension-values/{rate_dimension_value}', [RateDimensionValueController::class, 'destroy'])->name('rate-dimension-values.destroy');

    // Registered before the apiResource below so "resolve" isn't swallowed by
    // the {application_rate} show route.
    Route::get('application-rates/resolve', [ApplicationRateController::class, 'resolve'])->name('application-rates.resolve');
    Route::apiResource('application-rates', ApplicationRateController::class)->except('update');
    Route::patch('application-rates/{application_rate}', [ApplicationRateController::class, 'update'])->name('application-rates.update');
});

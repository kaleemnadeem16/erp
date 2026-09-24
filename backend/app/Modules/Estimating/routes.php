<?php

use App\Modules\Estimating\Http\Controllers\Api\V1\ApplicationRateController;
use App\Modules\Estimating\Http\Controllers\Api\V1\AssemblyController;
use App\Modules\Estimating\Http\Controllers\Api\V1\AssemblyGroupController;
use App\Modules\Estimating\Http\Controllers\Api\V1\FormulationController;
use App\Modules\Estimating\Http\Controllers\Api\V1\FormulationVersionController;
use App\Modules\Estimating\Http\Controllers\Api\V1\RateDimensionController;
use App\Modules\Estimating\Http\Controllers\Api\V1\RateDimensionValueController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('assembly-groups', AssemblyGroupController::class)->except('update');
    Route::patch('assembly-groups/{assembly_group}', [AssemblyGroupController::class, 'update'])->name('assembly-groups.update');
    Route::apiResource('assemblies', AssemblyController::class)->except('update');
    Route::patch('assemblies/{assembly}', [AssemblyController::class, 'update'])->name('assemblies.update');

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

    // Registered before the apiResource below so "resolve" isn't swallowed by
    // the {formulation} show route.
    Route::get('formulations/resolve', [FormulationController::class, 'resolve'])->name('formulations.resolve');
    Route::apiResource('formulations', FormulationController::class)->except('update');
    Route::patch('formulations/{formulation}', [FormulationController::class, 'update'])->name('formulations.update');
    Route::post('formulations/{formulation}/versions', [FormulationVersionController::class, 'store'])->name('formulations.versions.store');
    Route::patch('formulation-versions/{formulation_version}', [FormulationVersionController::class, 'update'])->name('formulation-versions.update');
    Route::delete('formulation-versions/{formulation_version}', [FormulationVersionController::class, 'destroy'])->name('formulation-versions.destroy');
    Route::post('formulation-versions/{formulation_version}/activate', [FormulationVersionController::class, 'activate'])->name('formulation-versions.activate');
    Route::get('formulation-versions/{formulation_version}/cost-breakdown', [FormulationVersionController::class, 'costBreakdown'])->name('formulation-versions.cost-breakdown');
});

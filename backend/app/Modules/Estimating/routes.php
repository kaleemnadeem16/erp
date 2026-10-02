<?php

use App\Modules\Estimating\Http\Controllers\Api\V1\ApplicationRateController;
use App\Modules\Estimating\Http\Controllers\Api\V1\AssemblyController;
use App\Modules\Estimating\Http\Controllers\Api\V1\AssemblyGroupController;
use App\Modules\Estimating\Http\Controllers\Api\V1\AssemblyVersionController;
use App\Modules\Estimating\Http\Controllers\Api\V1\FormulationController;
use App\Modules\Estimating\Http\Controllers\Api\V1\FormulationVersionController;
use App\Modules\Estimating\Http\Controllers\Api\V1\MaterialPreviewController;
use App\Modules\Estimating\Http\Controllers\Api\V1\MaterialSelectionPolicyController;
use App\Modules\Estimating\Http\Controllers\Api\V1\MaterialVarianceCapabilitiesController;
use App\Modules\Estimating\Http\Controllers\Api\V1\MaterialVariancePolicyController;
use App\Modules\Estimating\Http\Controllers\Api\V1\MaterialVarianceReasonController;
use App\Modules\Estimating\Http\Controllers\Api\V1\OverheadPolicyController;
use App\Modules\Estimating\Http\Controllers\Api\V1\RateDimensionController;
use App\Modules\Estimating\Http\Controllers\Api\V1\RateDimensionValueController;
use App\Modules\Estimating\Http\Controllers\Api\V1\ResourceRateController;
use App\Modules\Estimating\Http\Controllers\Api\V1\StockMixPolicyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('assembly-groups', AssemblyGroupController::class)->except('update');
    Route::patch('assembly-groups/{assembly_group}', [AssemblyGroupController::class, 'update'])->name('assembly-groups.update');
    Route::apiResource('assemblies', AssemblyController::class)->except('update');
    Route::patch('assemblies/{assembly}', [AssemblyController::class, 'update'])->name('assemblies.update');
    Route::post('assemblies/{assembly}/material-preview', MaterialPreviewController::class)->name('assemblies.material-preview');
    Route::post('assemblies/{assembly}/versions', [AssemblyVersionController::class, 'store'])->name('assemblies.versions.store');
    Route::patch('assembly-versions/{assembly_version}', [AssemblyVersionController::class, 'update'])->name('assembly-versions.update');
    Route::delete('assembly-versions/{assembly_version}', [AssemblyVersionController::class, 'destroy'])->name('assembly-versions.destroy');
    Route::post('assembly-versions/{assembly_version}/activate', [AssemblyVersionController::class, 'activate'])->name('assembly-versions.activate');
    Route::get('assembly-versions/{assembly_version}/cost-breakdown', [AssemblyVersionController::class, 'costBreakdown'])->name('assembly-versions.cost-breakdown');

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

    // Registered before the apiResource below so "resolve" isn't swallowed by
    // the {overhead_policy} show route.
    Route::get('material-selection-policies', [MaterialSelectionPolicyController::class, 'index'])->name('material-selection-policies.index');
    Route::post('material-selection-policies', [MaterialSelectionPolicyController::class, 'store'])->name('material-selection-policies.store');
    Route::get('stock-mix-policies', [StockMixPolicyController::class, 'index'])->name('stock-mix-policies.index');
    Route::post('stock-mix-policies', [StockMixPolicyController::class, 'store'])->name('stock-mix-policies.store');
    Route::get('overhead-policies/resolve', [OverheadPolicyController::class, 'resolve'])->name('overhead-policies.resolve');
    Route::apiResource('overhead-policies', OverheadPolicyController::class)->except('update');
    Route::patch('overhead-policies/{overhead_policy}', [OverheadPolicyController::class, 'update'])->name('overhead-policies.update');
    Route::post('overhead-policies/{overhead_policy}/activate', [OverheadPolicyController::class, 'activate'])->name('overhead-policies.activate');

    Route::get('material-variance-capabilities', MaterialVarianceCapabilitiesController::class)->name('material-variance-capabilities');
    Route::apiResource('material-variance-reasons', MaterialVarianceReasonController::class)->only(['index', 'store']);
    Route::patch('material-variance-reasons/{material_variance_reason}', [MaterialVarianceReasonController::class, 'update'])->name('material-variance-reasons.update');
    Route::apiResource('material-variance-policies', MaterialVariancePolicyController::class)->except('update');
    Route::patch('material-variance-policies/{material_variance_policy}', [MaterialVariancePolicyController::class, 'update'])->name('material-variance-policies.update');
    Route::post('material-variance-policies/{material_variance_policy}/activate', [MaterialVariancePolicyController::class, 'activate'])->name('material-variance-policies.activate');

    Route::apiResource('resource-rates', ResourceRateController::class)->except('update');
});

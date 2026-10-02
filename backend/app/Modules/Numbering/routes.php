<?php

use App\Modules\Numbering\Http\Controllers\Api\V1\NumberingConstantController;
use App\Modules\Numbering\Http\Controllers\Api\V1\NumberingCounterController;
use App\Modules\Numbering\Http\Controllers\Api\V1\NumberingDocumentTypeController;
use App\Modules\Numbering\Http\Controllers\Api\V1\NumberingPreviewController;
use App\Modules\Numbering\Http\Controllers\Api\V1\NumberingSeriesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('numbering')->group(function () {
    Route::get('document-types', [NumberingDocumentTypeController::class, 'index'])->name('numbering.document-types.index');
    Route::get('context', [NumberingDocumentTypeController::class, 'context'])->name('numbering.context');
    Route::post('preview', [NumberingPreviewController::class, 'preview'])->name('numbering.preview');

    Route::post('series', [NumberingSeriesController::class, 'store'])->name('numbering.series.store');
    Route::get('series/{series}/versions', [NumberingSeriesController::class, 'versions'])->name('numbering.series.versions');
    Route::post('series/{series}/versions', [NumberingSeriesController::class, 'storeVersion'])->name('numbering.series.versions.store');
    Route::get('series/{series}/counters', [NumberingSeriesController::class, 'counters'])->name('numbering.series.counters');
    Route::get('series/{series}/rules', [NumberingSeriesController::class, 'rules'])->name('numbering.series.rules');
    Route::post('series/{series}/rules', [NumberingSeriesController::class, 'storeRule'])->name('numbering.series.rules.store');
    Route::post('counters/{counter}/raise', [NumberingCounterController::class, 'raise'])->name('numbering.counters.raise');

    Route::get('constants', [NumberingConstantController::class, 'index'])->name('numbering.constants.index');
    Route::post('constants', [NumberingConstantController::class, 'store'])->name('numbering.constants.store');
});

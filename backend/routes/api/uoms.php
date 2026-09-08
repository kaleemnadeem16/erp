<?php

use App\Http\Controllers\Api\V1\UomController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/uoms', [UomController::class, 'index'])->name('uoms.index');
    Route::get('/uoms/{uom}', [UomController::class, 'show'])->name('uoms.show');
});

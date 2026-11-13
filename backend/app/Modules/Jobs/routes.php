<?php

declare(strict_types=1);

use App\Modules\Jobs\Http\Controllers\Api\V1\JobReadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('jobs', [JobReadController::class, 'index'])->name('jobs.index');
    Route::get('jobs/{job}', [JobReadController::class, 'show'])->whereNumber('job')->name('jobs.show');
    Route::get('jobs/{job}/history', [JobReadController::class, 'history'])->whereNumber('job')->name('jobs.history');
});

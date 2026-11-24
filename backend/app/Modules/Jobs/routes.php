<?php

declare(strict_types=1);

use App\Modules\Jobs\Http\Controllers\Api\V1\JobAssignmentLookupController;
use App\Modules\Jobs\Http\Controllers\Api\V1\JobCommandController;
use App\Modules\Jobs\Http\Controllers\Api\V1\JobReadController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('jobs', [JobReadController::class, 'index'])->name('jobs.index');
    Route::get('jobs/{job}', [JobReadController::class, 'show'])->whereNumber('job')->name('jobs.show');
    Route::get('jobs/{job}/history', [JobReadController::class, 'history'])->whereNumber('job')->name('jobs.history');
    Route::post('jobs/{job}/assign', [JobCommandController::class, 'jobsAssign'])->whereNumber('job')->name('jobsAssign');
    Route::post('jobs/{job}/start', [JobCommandController::class, 'jobsStart'])->whereNumber('job')->name('jobsStart');
    Route::post('jobs/{job}/holds', [JobCommandController::class, 'jobsHold'])->whereNumber('job')->name('jobsHold');
    Route::post('jobs/{job}/resolve-hold', [JobCommandController::class, 'jobsResolveHold'])->whereNumber('job')->name('jobsResolveHold');
    Route::post('jobs/{job}/complete', [JobCommandController::class, 'jobsComplete'])->whereNumber('job')->name('jobsComplete');
    Route::post('jobs/{job}/closeout-assessments', [JobCommandController::class, 'jobsAssessCloseout'])->whereNumber('job')->name('jobsAssessCloseout');
    Route::post('jobs/{job}/close', [JobCommandController::class, 'jobsClose'])->whereNumber('job')->name('jobsClose');
    Route::get('jobs/{job}/operation-context', [JobCommandController::class, 'jobsOperationContext'])->whereNumber('job')->name('jobsOperationContext');
    Route::get('jobs/{job}/completion-attestation-context', [JobCommandController::class, 'jobsCompletionAttestationContext'])->whereNumber('job')->name('jobsCompletionAttestationContext');
    Route::get('jobs/{job}/eligible-assignees', [JobAssignmentLookupController::class, 'jobsEligibleAssignees'])->whereNumber('job')->name('jobsEligibleAssignees');
});

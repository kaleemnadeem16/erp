<?php

use App\Modules\Approvals\Http\Controllers\Api\V1\ApprovalAuthorityController;
use App\Modules\Approvals\Http\Controllers\Api\V1\ApprovalDelegationController;
use App\Modules\Approvals\Http\Controllers\Api\V1\ApprovalPolicyController;
use App\Modules\Approvals\Http\Controllers\Api\V1\ApprovalRequestController;
use App\Modules\Approvals\Http\Controllers\Api\V1\ApprovalSubjectTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('approval-subject-types', [ApprovalSubjectTypeController::class, 'index'])->name('approval-subject-types.index');
    Route::put('approval-subject-types/{subject_type}/mode', [ApprovalSubjectTypeController::class, 'updateMode'])->name('approval-subject-types.mode');

    Route::apiResource('approval-policies', ApprovalPolicyController::class)->except('update')->parameters(['approval-policies' => 'approval_policy']);
    Route::patch('approval-policies/{approval_policy}', [ApprovalPolicyController::class, 'update'])->name('approval-policies.update');
    Route::post('approval-policies/{approval_policy}/activate', [ApprovalPolicyController::class, 'activate'])->name('approval-policies.activate');

    Route::get('approval-authorities/mine', [ApprovalAuthorityController::class, 'mine'])->name('approval-authorities.mine');
    Route::apiResource('approval-authorities', ApprovalAuthorityController::class)->only(['index', 'store', 'destroy'])->parameters(['approval-authorities' => 'approval_authority']);

    Route::apiResource('approval-delegations', ApprovalDelegationController::class)->only(['index', 'store', 'destroy'])->parameters(['approval-delegations' => 'approval_delegation']);

    Route::get('approval-requests', [ApprovalRequestController::class, 'index'])->name('approval-requests.index');
    Route::get('approval-requests/inbox', [ApprovalRequestController::class, 'inbox'])->name('approval-requests.inbox');
    Route::get('approval-requests/{approval_request}', [ApprovalRequestController::class, 'show'])->name('approval-requests.show');
    Route::post('approval-requests/{approval_request}/decide', [ApprovalRequestController::class, 'decide'])->name('approval-requests.decide');
    Route::post('approval-requests/{approval_request}/withdraw', [ApprovalRequestController::class, 'withdraw'])->name('approval-requests.withdraw');
});

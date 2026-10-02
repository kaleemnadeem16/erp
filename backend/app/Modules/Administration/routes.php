<?php

use App\Modules\Administration\Http\Controllers\MemberController;
use App\Modules\Administration\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

Route::get('members', [MemberController::class, 'index'])->name('members.index');
Route::post('members', [MemberController::class, 'store'])->name('members.store');
Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');
Route::patch('members/{member}', [MemberController::class, 'update'])->name('members.update');
Route::post('members/{member}/access', [MemberController::class, 'access'])->name('members.access');
Route::post('members/{member}/suspend', [MemberController::class, 'suspend'])->name('members.suspend');
Route::post('members/{member}/reactivate', [MemberController::class, 'reactivate'])->name('members.reactivate');
Route::post('members/{member}/resend-invitation', [MemberController::class, 'resend'])->name('members.resend-invitation');
Route::post('members/{member}/send-password-reset', [MemberController::class, 'sendPasswordReset'])->name('members.send-password-reset');
Route::post('members/{member}/issue-temporary-password', [MemberController::class, 'issueTemporaryPassword'])->middleware('throttle:5,1')->name('members.issue-temporary-password');
Route::get('members/{member}/history', [MemberController::class, 'history'])->name('members.history');
Route::get('permissions', [RoleController::class, 'permissions'])->name('permissions.index');
Route::apiResource('roles', RoleController::class);
